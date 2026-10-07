# FusionPBX REST API
An HTTP API for [FusionPBX](http://www.fusionpbx.com/). Based on [AccelerateNetworks/fusionpbx-rest-api](https://github.com/AccelerateNetworks/fusionpbx-rest-api).

# Install
Clone a release into FusionPBX's `app/` folder, into a folder called `rest_api`:

```
cd /var/www/fusionpbx/app
git clone --branch v1.0.0 https://github.com/CakeDC/fusionpbx-rest-api.git rest_api
```

Then log into the FusionPBX web interface, select Advanced -> Upgrade, check Schema, App Defaults, Menu Defaults and Permission Defaults, press Execute. (Permission Defaults is what gives the superadmin group the `rest_api_key_*` permissions. App Defaults adds the index on `v_xml_cdr.originating_leg_uuid` that `cdr-search` and `cdr-details` need on PostgreSQL; on a large CDR table it can take a few minutes, without blocking new call records.)

To update to another release, check out its tag (`git -C rest_api fetch --tags && git -C rest_api checkout v<version>`) and run the same Upgrade steps. Read [Upgrading from earlier versions](#upgrading-from-earlier-versions) first.

# Compatibility

Version 1.0.0 targets FusionPBX **5.6.5**. Releases are listed on the [releases page](https://github.com/CakeDC/fusionpbx-rest-api/releases).

# Use

## API keys

Every request is made with an API key, sent with HTTP Basic auth as `<key id>:<secret>`. Each key is bound to a FusionPBX user, and the request runs as that user. It has the permissions of the user's groups, acts on the user's domain, and records it creates show that user as `insert_user`.

Keys are managed under the REST API app in the FusionPBX menu. Managing keys needs the `rest_api_key_view`, `rest_api_key_add`, `rest_api_key_edit` and `rest_api_key_delete` permissions, which superadmins have by default. **Anyone who can add or edit keys can bind a key to any user, including a superadmin, and use the API as that user.** Only give these permissions to superadmins.

A key authenticates only when all of these hold:
- the key is enabled
- the key hasn't expired (an empty expiry means never)
- its user exists and is enabled
- the user's domain is enabled

Otherwise the request gets `401 {"error": "unauthorized"}`.

The secret is shown once, when the key is created. If you lose it, create a new key and delete the old one.

Recommended setup: create one user for each integration, in a custom group that only has the permissions of the actions the integration uses (see the table below). Bind the integration's key to that user.

## Requests

The API endpoint is shown on the API key page, usually `https://<your fusionpbx>/app/rest_api/rest.php`. Every request is an HTTP POST with a JSON body. The body's `action` parameter names the action (see below). For example, the `domain-details` action with `domain_name=fusionpbx.example.net`:

```
$ curl -s --user "5bc14e83-fc4e-4578-99b8-c7151eb2ec54:jM2GQuYgQTkIGE6nJ2SP" -d '{"action": "domain-details", "domain_name": "fusionpbx.example.net"}' https://fusionpbx.example.net/app/rest_api/rest.php | jq
{
  "domain_uuid": "3a644e67-de8f-4798-b07e-6f22c33a656e",
  "domain_parent_uuid": null,
  "domain_name": "fusionpbx.example.net",
  "domain_enabled": true,
  "domain_description": ""
}
```

## Permissions

Each action needs these FusionPBX permissions in the key user's groups:

| Action | Permissions |
|---|---|
| `cdr-details` | `xml_cdr_view` |
| `cdr-list` | `xml_cdr_view` |
| `cdr-search` | `xml_cdr_view` |
| `destination-create` | `destination_add`, `dialplan_add`, `dialplan_detail_add` |
| `destination-delete` | `destination_delete`, `dialplan_delete`, `dialplan_detail_delete` |
| `destination-details` | `destination_view` |
| `destination-list` | `destination_view` |
| `destination-update` | `destination_edit`, `dialplan_edit`, `dialplan_detail_add`, `dialplan_detail_delete` |
| `domain-details` | none |
| `domain-list` | `domain_view` (`domain_select` to see every domain) |
| `extension-create` | `extension_add`, `voicemail_add` (`extension_password` to also get the SIP password back, `extension_user_add` to link a user) |
| `extension-details` | `extension_view` |
| `extension-list` | `extension_view` |
| `extension-update` | `extension_edit`, plus per field: `caller_id_name` needs `effective_caller_id_name`, `outbound_caller_id_name`, `emergency_caller_id_name`; `caller_id_number` the same three `*_number` permissions; `enabled` needs `extension_enabled`; `user_uuid` needs `extension_user_add` (`extension_user_delete` for `null`) |
| `extension-user-list` | `extension_view`, `user_view` |
| `originate` | `click_to_call_call` |
| `ringgroup-create` | `ring_group_add`, `ring_group_destination_add`, `dialplan_add` |
| `ringgroup-list` | `ring_group_view`, `ring_group_destination_view` |
| `user-details` | `user_view` |
| `user-list` | `user_view` |

A missing permission returns `403 {"error": "forbidden", "missing_permissions": [...]}`.

## Domains

`domain_uuid` is optional on every action. Without it, the action acts on the key user's own domain. Acting on another domain needs the FusionPBX `domain_select` permission. Without it, any other `domain_uuid` returns `403 {"error": "forbidden"}`. With it, a domain that doesn't exist or is disabled returns `404 {"error": "domain not found"}`.

Lookups never return records of domains the user can't act on: they answer 404 as if the record didn't exist. One exception: `destination-details` without `domain_uuid`, by a user with `domain_select`, searches every domain. Use it to find the domain an inbound number belongs to.

## Errors

| Status | Meaning |
|---|---|
| 400 | Invalid body, unknown action, missing or invalid parameter (`missing_parameters` lists missing ones) |
| 401 | Unusable key (see above) |
| 403 | The user lacks a permission (`missing_permissions`), or the request is for another domain without `domain_select` |
| 404 | Record or domain not found |
| 500 | Server error, or an action that doesn't declare its permissions |

## Actions from other apps

Other FusionPBX apps can expose actions through an `app_api.php` file (call them with `"app": "<app name>"`). Each such action file must declare the FusionPBX permissions it needs, for example `$required_permissions = array("my_app_view");`, or `array()` for none. Actions that don't declare them aren't run. `do_action()` receives the request body and, as an optional second argument, the context: `domain_explicit`, `cross_domain` and `user_domain_uuid`. Do all the work inside `do_action()`: top-level code in the action file runs before `rest.php` checks `$required_permissions`.

## Upgrading from earlier versions

From 1.0.0:
- `extension-create` answers `201` instead of `200` on success. An existing number answers `409` instead of `500`, and an invalid `extension` or caller ID now answers `400` instead of being saved.
- `extension-list` returns `{"data": [...], "pagination": {...}}` instead of a bare array, 25 extensions per page by default (up to 200 with `per_page`), with the fields documented below instead of `extension_uuid`, `extension` and `emergency_caller_id_number` only. Callers must read `data` and follow the pages.

Version 1.0.0 is the first release. Coming from the AccelerateNetworks code, or from a checkout older than 1.0.0, note that it changes how keys work:
- After upgrading, run Advanced → Upgrade → Schema, Menu Defaults and Permission Defaults, then log out and back in (permissions are cached in the session) before editing keys. **Existing keys stop working** until a superadmin edits each one, picks a user and enables it.
- Each integration's user needs the permissions listed above.
- Responses only contain the documented columns. `extension-create` no longer returns the SIP password unless the user has `extension_password`. Anything that read other columns from `extension-create`, `destination-create`, `destination-details`, `ringgroup-create` or `domain-details` must be updated.
- `domain_uuid` is now optional. It defaults to the key user's domain.
- Also check App Defaults when you upgrade: it adds the `v_xml_cdr_originating_leg_uuid_idx` index used by `cdr-search` and `cdr-details`. Without it they still work, but slowly on large CDR tables.

# Actions
All actions are defined in the `actions/` directory of this repo. What follows is a best effort attempt to document them.

## `destination-create`

| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `number`      | yes      | Phone number to add | 
| `extension`   | yes      | Extension to transfer calls for this number to |

Creates a new destination in FusionPBX.

## `destination-delete`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `number`      | yes | Inbound number of the destination to delete |

Delete an inbound destination (ZuluCall's `deleteDestination`) with its dialplan and dialplan details, as FusionPBX's destinations page does, and clear the dialplan cache of its context. Answers `204` with no body. A number that isn't an inbound destination of the domain returns `404 {"error": "destination not found"}`, and an invalid number returns `400 {"error": "invalid number"}`.

## `destination-details`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `number`      | yes      | Inbound number to look up |
| `domain_uuid` | no | Domain to search. Defaults to the key user's domain; users with `domain_select` who leave it out search every domain |

looks up details for a particular destination

## `destination-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the inbound destinations (DIDs) of a domain, disabled ones included, sorted by number: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 12}}` (ZuluCall's `listDestinations`). Each item has `domain_uuid`, `number`, `destination_type`, `target` and `enabled` (boolean).

FusionPBX stores a destination as actions such as `transfer 100 XML <domain>`. When a destination has exactly one `transfer` action, its number is looked up in the domain:

| `destination_type` | When the number is | `target` |
|---|---|---|
| `voicemail` | `*99<box>`, a voicemail box | the box number |
| `ring_group` | a ring group's extension | the ring group's uuid |
| `ivr` | an IVR menu's extension | the IVR menu's uuid |
| `extension` | an extension's number or alias | the number |

Any other destination (a time condition, a call flow, a fax, several actions, a number nothing in the domain owns) has `destination_type` and `target` `null`. A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `destination-update`
| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid`      | no  | Domain to act on. Defaults to the key user's domain |
| `number`           | yes | Inbound number of the destination to update |
| `destination_type` | no  | `extension`, `ring_group`, `ivr` or `voicemail`; given together with `target` |
| `target`           | no  | The extension number (or alias), ring group uuid, IVR menu uuid or voicemail box number, in the domain |
| `enabled`          | no  | `true` or `false` |

Update an inbound destination (ZuluCall's `updateDestination`) and return it as `destination-list` does. Fields left out don't change; at least one is required (`400 {"error": "nothing to update"}` otherwise).

A new target replaces the destination's actions with one transfer to it (`<number> XML <context>`, `*99<box>` for voicemail): in the destination, in its dialplan's XML and in its dialplan details. Everything else FusionPBX put in the dialplan (recording, hold music, caller ID prefix, conditions...) stays as it is. If the dialplan no longer contains the destination's actions, because it was edited by hand, the update is refused with `409`. `enabled` switches both the destination and its dialplan. The dialplan cache is cleared as FusionPBX's destination page does.

A number that isn't an inbound destination of the domain returns `404 {"error": "destination not found"}`, a target outside the domain returns `404 {"error": "target not found"}`, and an invalid value returns `400 {"error": "invalid <parameter>"}`.

## `domain-details`

| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no       | UUID of the domain to look up. |
| `domain_name` | no       | Name of the domain to look up, used when `domain_uuid` is not given. |

looks up details of a domain. Mostly useful for converting between domain uuid and domain name. With neither parameter it returns the key user's domain.

## `domain-list`

| Parameter  | Required | Description |
|------------|----------|-------------|
| `page`     | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page` | no | Rows per page, 1 to 200 (default 25) |

List domains, sorted by name: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 2}}` (ZuluCall's `listDomains`). Each item has `domain_uuid`, `domain_name` and `domain_enabled` (boolean). Users with `domain_select` get every domain, disabled ones included; other users only get their own domain. `domain_uuid` is ignored. A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `extension-create`

| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid`      | no  | Domain to act on. Defaults to the key user's domain |
| `extension`        | yes | Extension number to create: digits, `*`, `#`, optional leading `+` |
| `caller_id_name`   | no  | Effective, outbound and emergency caller ID name. One line, up to 255 characters |
| `caller_id_number` | no  | Effective, outbound and emergency caller ID number (digits, `*`, `#`, optional leading `+`) |
| `user_uuid`        | no  | User of the domain to link the extension to (needs `extension_user_add`) |

Create an extension and its voicemail box (ZuluCall's `createExtension`). Answers `201` with the extension's details, plus its SIP `password` when the key user has `extension_password`.

A number that already exists in the domain returns `409 {"error": "extension already exists"}`, a `user_uuid` outside the domain returns `404 {"error": "user not found"}`, an invalid value returns `400 {"error": "invalid <parameter>"}`, and linking a user without `extension_user_add` returns `403` with `missing_permissions`.

## `extension-details`

| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `extension_uuid`   | yes      | Extension (by UUID) to look up  |

get all details of an extension

## `extension-update`

| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid`      | no  | Domain to act on. Defaults to the key user's domain |
| `extension_uuid`   | yes | Extension to update |
| `caller_id_name`   | no  | Sets the effective, outbound and emergency caller ID name, as `extension-create` does. One line, up to 255 characters; `""` clears it |
| `caller_id_number` | no  | Sets the effective, outbound and emergency caller ID number (digits, `*`, `#`, optional leading `+`); `""` clears it |
| `enabled`          | no  | `true` or `false` |
| `user_uuid`        | no  | Links the extension to this user of the domain; users already linked stay linked. `null` removes every link |

Update an extension (ZuluCall's `updateExtension`). Fields left out don't change; at least one is required (`400 {"error": "nothing to update"}` otherwise). Returns the extension with the same columns as `extension-details`, and clears FusionPBX's cached directory entry so the change applies without a reload.

Each field needs the permissions of the columns it writes, as in FusionPBX's extension edit page; a missing one refuses the whole update with `403` and `missing_permissions`. An extension or user that doesn't exist in the domain returns `404` (`extension not found`, `user not found`), and an invalid value returns `400 {"error": "invalid <parameter>"}`.

## `extension-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the extensions of a domain, disabled ones included, sorted by extension number (as text): `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 42}}` (ZuluCall's `listExtensions`). Each item has the fields of `extension-user-list`: `extension_uuid`, `extension`, `domain_uuid`, `directory_first_name`, `directory_last_name`, `emergency_caller_id_number`, `outbound_caller_id_number`, `enabled` (boolean) and `user_uuid`. An extension can be linked to several users; `user_uuid` is the one with the lowest uuid, or `null` when none is linked (use `extension-user-list` for a user's extensions). A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.


## `extension-user-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user whose extensions to list |

List the extensions linked to a user (`v_extension_users`), sorted by extension number (as text), disabled ones included, each once even if linked twice. A user can have several extensions; FusionPBX has no primary one, so the caller picks. Returns `{"data": [...]}` (ZuluCall's `listUserExtensions`). Each item has `extension_uuid`, `extension`, `domain_uuid`, `directory_first_name`, `directory_last_name`, `emergency_caller_id_number`, `outbound_caller_id_number`, `enabled` (boolean) and `user_uuid`.

A user without extensions returns `{"data": []}`. A user that is not in the domain returns `404 {"error": "user not found"}`, and a malformed `user_uuid` returns `400 {"error": "invalid user_uuid"}`.


## `user-details`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user to look up |

Return one FusionPBX user (ZuluCall's `getUser`), to check that a stored `domain_uuid` + `user_uuid` pair still resolves to a user: `user_uuid`, `domain_uuid`, `username` and `user_enabled` (boolean). A disabled user is returned with `user_enabled: false`. A user that doesn't exist or belongs to another domain returns `404 {"error": "user not found"}`, and a malformed `user_uuid` returns `400 {"error": "invalid user_uuid"}`.

## `user-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the FusionPBX users of a domain, disabled ones included, sorted by username: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 3}}` (ZuluCall's `listUsers`). Each item has `user_uuid`, `domain_uuid`, `username` and `user_enabled` (boolean); passwords and API keys are never returned. A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `ringgroup-create`
| Parameter      | Required | Description |
|----------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `name`         | yes      | name for the ring group |
| `extension`    | yes      | Extension to route TO the ring group |
| `destinations` | yes      | JSON array of extensions to send calls from the ring group. Example: `[{"number": "100"}, {"number": "101"}, {"number": "102"}]` |
| `strategy`     | yes      | one of: `simultaneous`, `sequence`, `enterprise`, `rollover` or `random` |

Create a ring group

## `ringgroup-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the ring groups of a domain, disabled ones included, sorted by extension: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 3}}` (ZuluCall's `listRingGroups`). Each item has `ring_group_uuid`, `domain_uuid`, `name`, `extension`, `strategy` and `destinations` (`[{"number": "101"}, ...]`, in the order FusionPBX shows them: by delay, then number). A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `originate`
| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `caller_id_number` | yes      | caller ID number to display for both legs of the call |
| `caller_id_name`   | no       | an optional caller ID name to request. typically will be delivered for internal calls and stripped by the upstream provider for external calls |
| `destination_a`    | yes      | the number to call first  |
| `destination_b`    | yes      | the number to call second |

Call one number (destination_a) and connect the call to another number (destination_b) when it's picked up. The selected domain's internal dialplan is used, so internal extensions may be dialed.

Note that the call is ended when destination_a ends the call, so if one leg isn't expected to hang up, make it destination_b.

Use `destination_b=*9664` to indefinitely play hold music to destination_a.

## `cdr-search`
| Parameter        | Required | Description |
|------------------|----------|-------------|
| `domain_uuid`    | no | Domain to act on. Defaults to the key user's domain |
| `start_date`     | no | Calls that started at or after this ISO 8601 date-time (UTC without an offset). A date alone (`2026-09-01`) means 00:00 UTC of that day |
| `end_date`       | no | Calls that started at or before this date-time. A date alone includes the whole day (UTC). Not before `start_date` |
| `direction`      | no | `inbound`, `outbound` or `local` |
| `extension_uuid` | no | Comma-separated string or array of up to 100 extension uuids. Calls where any leg belongs to one of them, so the extension that received a call sees it as well as the one that made it. Each call is returned once |
| `counterparty`   | no | Text (up to 64 characters) the caller or destination number contains. `%` and `_` are plain characters |
| `own_number`     | no | Comma-separated string or array of up to 100 of the viewer's own numbers. With `counterparty`, only the other party is searched: the destination when the caller is one of them, else the caller when the destination is one of them, else both |
| `missed`         | no | `true` for missed calls only, `false` to leave them out |
| `calls_only`     | no | `true` (default): one row per call. `false`: one row per leg |
| `sort`           | no | `-start_stamp` (default, newest first) or `start_stamp` |
| `page`           | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`       | no | Rows per page, 1 to 200 (default 25) |

Search the call detail records (`v_xml_cdr`). Booleans may be JSON booleans, `"true"`/`"false"`, `1`/`0` or `"1"`/`"0"`; `page` and `per_page` may be integers or digit strings.

FusionPBX writes one record per call leg and doesn't give the legs of a call a shared id: an `a` leg's `bridge_uuid` is the `xml_cdr_uuid` of the `b` leg it was bridged to, and a `b` leg's `originating_leg_uuid` is the `xml_cdr_uuid` of its `a` leg, so every leg a ring group rang points at the same `a` leg. With `calls_only`, a call is shown as its `a` leg, or, when that leg isn't in the domain, as the earliest of the `b` legs that share an `originating_leg_uuid`. Every filter except `extension_uuid` applies to that row, and `total` counts calls. Only direct links are followed (no transfer chains), and this linking hasn't yet been checked against a production CDR export.

```json
{
  "data": [
    {
      "xml_cdr_uuid": "c0000001-0000-4000-8000-00000000000a",
      "direction": "inbound",
      "caller_id_name": "ACME",
      "caller_id_number": "+15550001111",
      "destination_number": "5000",
      "start_stamp": "2026-09-01 09:00:00+00",
      "end_stamp": "2026-09-01 09:01:35+00",
      "duration": 95,
      "hangup_cause": "NORMAL_CLEARING",
      "hangup_cause_q850": 16,
      "missed_call": false,
      "leg": "a",
      "bridge_uuid": "c0000001-0000-4000-8000-0000000000b1",
      "originating_leg_uuid": null,
      "extension_uuid": null,
      "record_name": "c1.wav",
      "record_path": "/var/lib/freeswitch/recordings/tenant1.example.com/archive/2026/Sep/01",
      "call_center_queue_uuid": null,
      "cc_queue": null
    }
  ],
  "pagination": {"page": 1, "per_page": 25, "total": 1}
}
```

`record_name` and `record_path` (the recording's directory on the PBX) are `null` for calls that weren't recorded. A page past the last returns `"data": []` with the correct `total`. An invalid parameter returns `400 {"error": "invalid <parameter>"}`.

## `cdr-details`
| Parameter      | Required | Description |
|----------------|----------|-------------|
| `domain_uuid`  | no | Domain to act on. Defaults to the key user's domain |
| `xml_cdr_uuid` | yes | Any leg of the call |

Return a call with every one of its legs, linked as in `cdr-search`, oldest first: `{"xml_cdr_uuid": "<uuid of the main leg>", "legs": [...]}`. Each leg has the fields of a `cdr-search` row, so `leg`, `extension_uuid`, `record_name` and `record_path` tell which extensions took part and where the recording is.

A call that doesn't exist or belongs to another domain returns `404 {"error": "call not found"}`; a missing or malformed `xml_cdr_uuid` returns `400 {"error": "invalid xml_cdr_uuid"}`.

## `cdr-list`
| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |

Legacy: returns the last 100 call detail records (one per leg) with no filters. New clients should use `cdr-search` and `cdr-details`.

# Development

## Tests
The tests need PHP 8.3 or newer and [Composer](https://getcomposer.org/). They don't need FusionPBX: `tests/Support/fusionpbx/` provides stand-ins for the FusionPBX functions and classes the plugin uses, including a small in-memory database.

```
composer install
composer test
```

* `tests/Unit`: the `lib/` helpers and every action, each test in its own PHP process.
* `tests/Http`: `rest.php` and the key management pages, served by PHP's built-in web server from a temporary FusionPBX-like document root.

The in-memory database only shows that the plugin's SQL does what it should, not that PostgreSQL accepts it. The `pgsql` suite (`tests/Pgsql`) runs the `cdr-search` and `cdr-details` tests, and the App Defaults index, on a real PostgreSQL with FusionPBX 5.6.5's `v_xml_cdr` columns, through PDO the way FusionPBX's `database` class uses it. It needs Docker:

```
composer test-pgsql                         # PostgreSQL 18 (the FusionPBX installer's default), PHP 8.3
POSTGRES_VERSION=16 PHP_VERSION=8.4 composer test-pgsql
```

To use a PostgreSQL of your own, set `REST_API_PGSQL_DSN` (e.g. `pgsql:host=127.0.0.1 port=5432 dbname=test user=test password=test`) and run `vendor/bin/phpunit --testsuite pgsql`; PHP needs `pdo_pgsql`. The suite creates and empties `v_xml_cdr` and creates `v_xml_cdr_originating_leg_uuid_idx`, so never point it at a FusionPBX database.

To check that the tests catch a regression, run them against another checkout of the plugin, for example an older commit:

```
git worktree add /tmp/rest_api_old <commit>
PLUGIN_DIR=/tmp/rest_api_old composer test
```

## Coverage
`composer coverage` runs the suite with [Xdebug](https://xdebug.org/) in coverage mode and writes an HTML report to `build/coverage/html/index.html`. It merges the unit tests' coverage with the lines run by requests to the HTTP test server, so `rest.php`, `index.php` and `key_edit.php` are included.

Xdebug must be installed (e.g. `sudo apt install php8.5-xdebug`). The script turns on coverage mode itself. Without Xdebug locally, use a Docker image that has it, e.g. a ddev web image:

```
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD:/app -w /app --entrypoint bash ddev/ddev-webserver:<tag> -c '
  mkdir -p /tmp/ini && printf "zend_extension=xdebug\nopcache.enable_cli=0\n" > /tmp/ini/xdebug.ini
  PHP_INI_SCAN_DIR=":/tmp/ini" XDEBUG_MODE=coverage composer coverage'
```

The test stand-ins refuse to run when requested through a web server outside the test suite, but `vendor/` and `tests/` don't need to be deployed to FusionPBX.
