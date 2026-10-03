# Bind REST API keys to FusionPBX users and enforce their permissions

- Ticket: [#43940](https://redmine.cakedc.com/issues/43940) (depends on #43939, done)
- Related: [#43945](https://redmine.cakedc.com/issues/43945) — release tags and FusionPBX compatibility (out of scope here)
- Target: FusionPBX **5.6.5** (tag `5.6.5`, commit `ac4cd29`)
- Release: breaking change, planned as plugin `v1.0.0` (see #43945)

## Goal

Every API request runs as a real FusionPBX user: it has that user's group permissions, it is scoped to that user's domain, and records it creates carry that user as `insert_user`. The plugin keeps its own keys (`rest_api_keys`, bcrypt-hashed secrets) and never reads or writes `v_users.api_key`, which belongs to the official FusionPBX API.

This closes findings #7 (keys are global and unscoped) and #8 (actions grant themselves permissions through `$_SESSION`) from #43939.

### Decisions taken during design

- A per-key endpoint/domain allowlist was considered and rejected. In 5.6.5, `database::save()` checks real FusionPBX permissions, so a key-level ACL would still have to fake them. Per-integration restriction is done with one service user per integration, in a custom group.
- Cross-domain access uses FusionPBX's `domain_select` permission (5.6.5 has no `domain_all`).
- If a key's user is deleted, the key stays and is rejected with 401.
- Plugin versioning is handled separately in #43945.

### Non-goals

- Restricting which users a key admin can bind keys to (key management stays superadmin-level).
- Translations for the admin pages (strings stay in English, as today).
- Secret rotation for existing keys.

## FusionPBX 5.6.5 behaviour this design relies on

Verified against the `5.6.5` tag:

1. `permissions::__construct($database, $domain_uuid, $user_uuid)` uses `$_SESSION['permissions']` when it is set. Otherwise it loads the permissions of the user's groups, through `groups::assigned()`, filtered by `domain_uuid`. `->session()` copies them into `$_SESSION['permissions']`.
2. `permissions::new()` is a **singleton**. `permission_exists()` calls it with the globals `$database`, `$domain_uuid` and `$user_uuid`. Whatever the first call builds stays for the rest of the request.
3. `require.php` creates the shared connection with `database::new()` before any user is known. `database::new(['user_uuid' => …, 'domain_uuid' => …])` updates that shared instance. `new database` reads `$_SESSION['user_uuid']` and `$_SESSION['domain_uuid']` when constructed. `save()` writes `insert_user` / `update_user` from the instance's `user_uuid`.
4. `database::save()` checks `<table>_add` / `<table>_edit` for parent tables and `<child>_add` / `<child>_edit` for child tables. A missing permission **silently skips** that table; there is no error.
5. `require.php:157` calls `permission_exists('domain_select')` when the query string has `domain_change=true` and a `domain_uuid`. Before auth, this would make the singleton with no permissions.
6. `groups::assigned()` filters by `u.domain_uuid`, so permissions must be loaded with the user's **own** domain.
7. `v_users.user_enabled` and `v_domains.domain_enabled` are booleans (`'true'` / `'false'` toggles).

## 1. Schema (`app_config.php`)

New columns on `rest_api_keys`, using the same multi-database type pattern as the existing columns:

| Field | pgsql | sqlite | mysql | Notes |
|---|---|---|---|---|
| `user_uuid` | `uuid` | `text` | `char(36)` | Nullable. No foreign key or cascade to `v_users`. |
| `key_enabled` | `boolean` | `text` | `text` | Toggle `true` / `false`. Only `true` passes auth; null is rejected. |
| `expires` | `timestamptz` | `date` | `date` | Nullable. Null means the key never expires. |

`domain_uuid` is **not** stored on the key. It comes from the user on every request, so it can't drift out of sync.

Upgrade → Schema adds the columns. Existing keys have `user_uuid` and `key_enabled` null, so they are rejected until an admin edits them (see Migration).

## 2. Authentication and request setup (`rest.php`)

Order matters because of the singletons above.

1. **Isolate the session**, unchanged from #43939: drop any session started by `session.auto_start`, set `$no_session`, disable session cookies.
2. **`$_GET = array();`** before `require.php`. The API only reads the JSON body. This stops `require.php:157` from creating the permissions singleton before auth.
3. `require.php`, then `lib/input_validation.php`, then the existing "never save the session" guards.
4. **Parse Basic auth.** Missing credentials → 401. If `PHP_AUTH_USER` fails `is_uuid()` → 401.
5. **Look up the key** with one query:
   ```sql
   SELECT k.key_secret, k.key_enabled, k.expires,
          u.user_uuid, u.username, u.user_enabled,
          d.domain_uuid, d.domain_name, d.domain_enabled
   FROM rest_api_keys k
   LEFT JOIN v_users u   ON u.user_uuid = k.user_uuid
   LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid
   WHERE k.key_uuid = :key_id
   ```
6. **Verify the secret** with `password_verify`. When the key doesn't exist, run the existing dummy `password_verify`, so timing doesn't reveal which key IDs exist.
7. **Reject with 401** (`{"error": "unauthorized"}`, identical body every time) when any of these hold:
   - the secret is wrong or the key doesn't exist
   - `key_enabled` is not `true`
   - `expires` is set and in the past
   - `user_uuid` is null, or the join found no user (the user was deleted)
   - `user_enabled` is not `true`
   - `domain_enabled` is not `true`

   Each rejection writes its specific reason and the `key_uuid` to `error_log`. The secret is never logged.
8. **Set up the request as the key's user**, before any permission check:
   ```php
   $_SESSION['domain_uuid'] = $domain_uuid;  // also set as globals:
   $_SESSION['domain_name'] = $domain_name;  // $domain_uuid, $user_uuid
   $_SESSION['user_uuid']   = $user_uuid;
   $_SESSION['username']    = $username;
   $database = database::new(['user_uuid' => $user_uuid, 'domain_uuid' => $domain_uuid]);
   (new groups($database, $domain_uuid, $user_uuid))->session();   // if_group()
   permissions::new($database, $domain_uuid, $user_uuid)->session();
   ```
   If `groups` in 5.6.5 doesn't load the assigned groups in its constructor, call `assigned()` first. Confirm this during implementation.
9. Update `last_used`, as today.
10. **Route the action**, as today: `action` and `app` name validation and the `app_api.php` path checks stay unchanged.
11. **Resolve the domain** (Section 4), then check **permissions** (Section 3), then validate parameters with `ensure_parameters`, then call `do_action($body, $context)`.
12. The session is never saved and no cookie is sent (logic unchanged from #43939).

## 3. Permissions for each action

Each action file declares the FusionPBX permissions it needs, next to `$required_params`:

```php
$required_permissions = array("extension_add", "voicemail_add");
```

In `rest.php`, after the action file is included:

- `$required_permissions` is not set or not an array → **500** `{"error": "action does not declare permissions"}`, and the action doesn't run. This **fails closed**, and it applies to third-party `app_api.php` actions too.
- `array()` is valid and means no FusionPBX permission is needed. Auth and domain scoping still apply.
- Each permission is checked with `permission_exists()`. If any are missing → **403** `{"error": "forbidden", "missing_permissions": [...]}`.
- This check runs **before** `ensure_parameters`, so a caller without access doesn't learn which parameters an action expects.

Each list must cover every table the action saves. Otherwise `database::save()` silently skips a table (behaviour 4).

| Action | Tables saved / what it does | `$required_permissions` |
|---|---|---|
| `extension-create` | `extensions`, `voicemails` | `extension_add`, `voicemail_add` |
| `destination-create` | `destinations`, `dialplans` with child `dialplan_details` | `destination_add`, `dialplan_add`, `dialplan_detail_add` |
| `ringgroup-create` | `ring_groups` with child `ring_group_destinations`, `dialplans` | `ring_group_add`, `ring_group_destination_add`, `dialplan_add` |
| `extension-details` | read | `extension_view` |
| `extension-list` | read | `extension_view` |
| `destination-details` | read | `destination_view` |
| `cdr-list` | read | `xml_cdr_view` |
| `originate` | starts a call through the event socket | `click_to_call_call` |
| `domain-details` | read | `array()` (domain scoping only) |

All `$_SESSION["permissions"][...] = true` lines are removed from `destination-create`, `extension-create` and `ringgroup-create`.

## 4. Domain scoping

In `rest.php`, after auth and routing, before the permission check:

1. If the body has `domain_uuid`, it must pass `is_uuid()`, otherwise **400**. `$context['domain_explicit'] = true`.
2. If not, set `$body->domain_uuid` to the key user's own domain. `$context['domain_explicit'] = false`.
3. `$context['cross_domain'] = permission_exists('domain_select')`.
4. If `$body->domain_uuid` is not the user's own domain:
   - without `domain_select` → **403** `{"error": "forbidden"}`. This happens before any lookup, so it doesn't reveal whether the domain exists;
   - with `domain_select`, the domain must exist and be enabled, otherwise **404** `{"error": "domain not found"}`.
5. Permissions always come from the user's own domain. Acting on another domain doesn't change which groups apply.

`domain_uuid` is removed from every action's `$required_params`, since `rest.php` always fills it in.

The action is called as `do_action($body, $context)`. PHP ignores extra arguments to user functions, so third-party actions declared as `do_action($body)` keep working.

Effect on each action:

| Action | Behaviour |
|---|---|
| `extension-*`, `cdr-list`, `originate`, `*-create` | Use `$body->domain_uuid`, which `rest.php` has already checked. The duplicate domain-existence lookup is kept only where the action needs `domain_name` (dialplan context, originate). |
| `domain-details` by `domain_uuid` | Returns the domain checked above. |
| `domain-details` by `domain_name` | Used when `domain_explicit` is false and `domain_name` is sent (the `domain_uuid` that `rest.php` filled in by default is ignored). The name is resolved to a UUID. If that domain isn't the user's own and the user lacks `domain_select`, the action returns **404** `domain not found`, not 403, the same as a name that doesn't exist, so names don't reveal which domains exist. When the body has both `domain_uuid` and `domain_name`, `domain_uuid` wins (today's behaviour). With neither, the user's own domain is returned. |
| `destination-details` | Searches `WHERE destination_number = :number AND domain_uuid = :domain_uuid`. **Exception:** when `domain_explicit` is false and `cross_domain` is true, it searches every domain, keeping the "which tenant owns this number" lookup. Not found → 404. |
| `destination-create` | The check that the number doesn't already exist stays global, because destination numbers must be unique across the system. It returns only "already exists", never the other domain's data. |

## 5. Trimming responses

No action uses `SELECT *`. Each returns an explicit column list. Shared lists live in `lib/fields.php`.

| Action | Returned fields |
|---|---|
| `extension-create` | Same columns as `extension-details` (shared list). It also returns `password` **only if** the user has `extension_password`. |
| `extension-details` | Unchanged list. `password` is never returned. |
| `destination-create`, `destination-details` | `destination_uuid`, `domain_uuid`, `destination_number`, `destination_type`, `destination_actions` (still decoded from JSON, #5), `destination_context`, `destination_enabled`, `destination_description`, `dialplan_uuid`, `insert_date`, `update_date` |
| `ringgroup-create` | `ring_group_uuid`, `domain_uuid`, `ring_group_name`, `ring_group_extension`, `ring_group_strategy`, `ring_group_enabled`, `ring_group_description`, `dialplan_uuid`, plus the created destinations |
| `domain-details` | `domain_uuid`, `domain_parent_uuid`, `domain_name`, `domain_enabled`, `domain_description` |
| `extension-list`, `cdr-list` | Unchanged (already explicit, no secrets) |

The response's top-level shape doesn't change. Clients that read columns not on these lists will break; the README migration note covers this.

## 6. Admin UI and plugin permissions

### Permissions (`app_config.php`)

- Remove `rest_api_manage_keys`.
- Add `rest_api_key_view`, `rest_api_key_add`, `rest_api_key_edit` and `rest_api_key_delete`, granted to `superadmin` by default.
- `app_menu.php` doesn't change.

### List page (`index.php`)

- Needs `rest_api_key_view`, otherwise "permission denied".
- "New" button only with `rest_api_key_add`. Delete buttons and the delete POST handler only with `rest_api_key_delete`.
- Columns: Name, Key ID, **User** (`username@domain_name`, or a "no user" warning), **Enabled**, **Expires** (marked "expired" when past), Created, Last used.

### Edit page (`key_edit.php`)

- Creating a key (POST without `key_uuid`) needs `rest_api_key_add`. Updating needs `rest_api_key_edit`. Viewing needs `rest_api_key_view`; without edit permission the fields are read-only and there is no save button.
- Fields:
  - **Name**: as today.
  - **User** (required): a dropdown of users from every domain, labelled `username@domain_name`, sorted by domain and then username. Disabled users are labelled "(disabled)". On save the server checks the value with `is_uuid()` and confirms the user exists in `v_users`; otherwise it shows an error and saves nothing.
  - **Enabled**: on by default for new keys.
  - **Expires**: optional date and time. Empty means never.
- The secret is still shown only once, on creation, with the copy button.
- The existing CSRF token checks stay.

### Security note

Anyone with `rest_api_key_add` or `rest_api_key_edit` can bind a key to any user, including a superadmin, and then call the API as that user. These permissions stay superadmin-only by default, and the README says so.

## 7. README

- Authentication: a key acts as its FusionPBX user. Requirements: key enabled and not expired, user and domain enabled.
- Recommended setup: one service user per integration, in a custom group with only the permissions it needs.
- A table of the permissions each action requires (Section 3).
- Domain scoping: the default domain, `domain_select` for other domains, and the `destination-details` lookup across all domains.
- The response field changes, and when `password` is returned by `extension-create`.
- Error codes: 400, 401, 403 (with `missing_permissions`), 404, 500.
- Third-party `app_api.php` actions must declare `$required_permissions`. They receive `$context` as an optional second argument.
- Migration note (Section 9).
- Target version: FusionPBX 5.6.5.

## 8. Testing

### Updating the stand-ins (`tests/Support/fusionpbx/`)

- `permissions` stand-in matching 5.6.5: a singleton; uses `$_SESSION['permissions']` when set; otherwise loads from fake `v_user_groups` and `v_group_permissions`, filtered by domain; `session()`. `permission_exists()` goes through it.
- `groups` stand-in: `assigned()` and `session()` set `$_SESSION['groups']`.
- `database::new()` stand-in: a singleton that keeps `user_uuid` / `domain_uuid`. `save()` skips tables whose `<table>_add` permission is missing and records the skip. It fills in `insert_user`.
- `require.php` stand-in: calls `permission_exists('domain_select')` when `$_GET['domain_change'] == 'true'`, like the real `require.php:157`.
- `fake_sql`: minimal `LEFT JOIN … ON a.x = b.y` support for the auth query.

### Unit tests (`tests/Unit/Actions`)

- Each action declares exactly the permissions in the Section 3 table.
- Run with only the declared permissions, every table is saved (no skips recorded).
- No action writes to `$_SESSION['permissions']` (compare session before and after, and scan `actions/`).
- No `SELECT *` in `actions/` (scan).
- Each response contains exactly the listed fields. `extension-create` returns `password` only with `extension_password`.
- `destination-details` filters by domain, and searches every domain only when `domain_explicit = false` and `cross_domain = true`.
- `domain-details`: a name outside the user's scope returns 404; with no `domain_uuid` or `domain_name`, it returns the user's own domain.

### HTTP tests (`tests/Http`)

- 401 with the same body for each of: no user, deleted user, disabled user, disabled domain, disabled key, null `key_enabled`, expired key, wrong secret, unknown key. Accepted: `expires` in the future, and `expires` empty.
- Request setup: the action sees the key user and domain in `$_SESSION`. `if_group()` sees the user's groups. A record created through the API has `insert_user` set to the key's user.
- 403 with `missing_permissions` when a permission is missing, returned before 400 for missing parameters.
- 500 for a built-in fake action without `$required_permissions`, and for a third-party `app_api.php` fake action without it.
- Domains:
  - no `domain_uuid` → the user's own domain is used
  - another domain without `domain_select` → 403
  - another domain with `domain_select` → succeeds
  - with `domain_select`, a domain that doesn't exist or is disabled → 404
  - an invalid UUID → 400
- Regression: a request with `?domain_change=true&domain_uuid=…` still gets the user's real permissions.
- Existing session isolation tests from #43939 keep passing unchanged.

### Admin tests (`tests/Http/AdminKeysTest.php`)

- Each `rest_api_key_*` permission gates its page or button and the matching POST.
- The user dropdown lists users from every domain as `username@domain_name`, and marks disabled users.
- Saving rejects a user UUID that is invalid or doesn't exist.
- `key_enabled` and `expires` are saved.
- The list page shows "no user" and "expired".

### Process

- TDD for each change. `composer test` stays green. `composer coverage` covers every new branch in `rest.php`, `index.php` and `key_edit.php`.

### Manual smoke test on FusionPBX 5.6.5 (at deploy time; no 5.6.5 server yet)

1. Upgrade → Schema and Menu Defaults. The new `rest_api_keys` columns and the `rest_api_key_*` permissions exist.
2. An existing key returns 401. The list shows it as "no user".
3. Create a group `api_test` with `extension_add`, `voicemail_add`, `extension_view` and `destination_view`, and a service user `api_test@<domain>` in it.
4. Create a key for that user. Call `extension-create`. The response has no `password`, and the database row has `insert_user` set to the service user.
5. Call `ringgroup-create`. Expect 403 with `missing_permissions` listing the `ring_group_*` and `dialplan_add` permissions.
6. Add `extension_password` to the group. `extension-create` now returns `password`.
7. Call `extension-list` with another domain's `domain_uuid`. Expect 403. Add `domain_select` to the group: expect 200. Use a random UUID: expect 404.
8. Call `destination-details` without `domain_uuid`, for a number in another domain. Expect 404 without `domain_select`, and a match with it.
9. Call any action with `?domain_change=true&domain_uuid=<uuid>` in the URL. Permissions still apply normally.
10. Disable the user, then the domain, then the key, then set `expires` in the past. Each returns 401. Delete the user: the key returns 401 and the list shows "no user".
11. The browser session is unaffected while calling the API from the same browser (#43939 behaviour).

## 9. Migration (breaking change)

- After the upgrade, existing keys have no user and `key_enabled` null, so they return 401. A superadmin must edit each key, pick a user and enable it.
- Clients must:
  - use a key bound to a user whose groups grant the permissions in Section 3
  - stop reading response columns that are no longer returned
- `domain_uuid` becomes optional on every action. It defaults to the key user's domain.

## Acceptance criteria

- Each key is linked to a user. Requests are rejected with 401 when the key has no user, its user was deleted, it is expired, or the key, user or domain is disabled.
- `v_users.api_key` is not read or written by the plugin.
- Every action declares and checks its required permissions and returns 403 when one is missing. Undeclared actions return 500. No action writes to `$_SESSION["permissions"]`.
- Requests are scoped to the key user's domain unless the user has `domain_select`. Lookups of records in other domains return 404.
- No session cookie is created or modified by API requests.
- Records created through the API have the key's user as `insert_user`.
- No action returns `SELECT *` rows. `password` is returned only by `extension-create`, and only with `extension_password`.
- Key management uses the `rest_api_key_*` permissions.
- README updated, including the migration note.
- `composer test` passes. The manual 5.6.5 checklist is documented.
