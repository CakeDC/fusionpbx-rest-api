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
| `call-answer` | `rest_api_call_control` |
| `call-hangup` | `call_active_hangup` |
| `call-hold` | `rest_api_call_control` |
| `call-list` | `call_active_view` |
| `call-resume` | `rest_api_call_control` |
| `call-transfer` | `call_active_transfer` |
| `call-transfer-attended` | `call_active_transfer` |
| `callcenter-agent-list` | `call_center_agent_view`, `call_center_tier_view` |
| `callcenter-agent-state` | `call_center_agent_view`, `call_center_agent_edit` |
| `callcenter-agent-status` | `call_center_agent_view`, plus `call_center_agent_edit` to set the status |
| `callcenter-queue-list` | `call_center_queue_view` |
| `callcenter-queue-status` | `call_center_active_view` |
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
| `extension-delete` | `extension_delete`, `extension_user_delete`, `follow_me_delete`, `follow_me_destination_delete`, `ring_group_destination_delete`, `extension_setting_delete`, `voicemail_delete`, `voicemail_option_delete`, `voicemail_message_delete`, `voicemail_destination_delete`, `voicemail_greeting_delete` (the admin and superadmin groups have them all by default) |
| `extension-details` | `extension_view` |
| `extension-list` | `extension_view` |
| `extension-update` | `extension_edit`, plus per field: `caller_id_name` needs `effective_caller_id_name`, `outbound_caller_id_name`, `emergency_caller_id_name`; `caller_id_number` the same three `*_number` permissions; `enabled` needs `extension_enabled`; `user_uuid` needs `extension_user_add` (`extension_user_delete` for `null`) |
| `extension-user-list` | `extension_view`, `user_view` |
| `originate` | `click_to_call_call` |
| `recording-details` | `call_recording_view` |
| `recording-download` | `call_recording_download` |
| `ringgroup-create` | `ring_group_add`, `ring_group_destination_add`, `dialplan_add` |
| `ringgroup-delete` | `ring_group_delete`, `ring_group_user_delete`, `ring_group_destination_delete`, `dialplan_delete`, `dialplan_detail_delete` |
| `ringgroup-details` | `ring_group_view`, `ring_group_destination_view` |
| `ringgroup-list` | `ring_group_view`, `ring_group_destination_view` |
| `ringgroup-update` | `ring_group_edit`, plus `dialplan_edit` to change `name` and `ring_group_destination_add`, `ring_group_destination_delete` to change `destinations` |
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
- `originate` calls `destination_a` first, as documented (it used to call `destination_b` first), and answers `201` with the call (`call_uuid`, `domain_uuid`, `state`, `caller_id_number`, `destination_number`) instead of `{"success": ..., "call_uuid": ...}`. A call FreeSWITCH can't place now answers `500` instead of `200` with `success: false`.
- Run Advanced → Upgrade → Permission Defaults: it adds the plugin's `rest_api_call_control` permission (to the superadmin and admin groups), which the call control actions need.
- `extension-create` and `extension-details` return `enabled` as a JSON boolean, like `extension-list`, whatever the database's column type (it was `"true"`/`"false"` text on sqlite and mysql).
- `extension-create` answers `201` instead of `200` on success. An existing number answers `409` instead of `500`, and an invalid `extension` or caller ID now answers `400` instead of being saved.
- `ringgroup-create` answers `201` instead of `200`, with the ring group as `ringgroup-details` returns it (`ring_group_uuid`, `domain_uuid`, `name`, `extension`, `strategy`, `destinations`) instead of FusionPBX's columns and `ring_group_destinations`. An existing extension answers `409` instead of `500`. `destinations` may now be a JSON array; invalid destinations answer `400 {"error": "invalid destinations"}` instead of the previous messages.
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

Delete an inbound destination with its dialplan and dialplan details, as FusionPBX's destinations page does, and clear the dialplan cache of its context. Answers `204` with no body. A number that isn't an inbound destination of the domain returns `404 {"error": "destination not found"}`, and an invalid number returns `400 {"error": "invalid number"}`.

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

List the inbound destinations (DIDs) of a domain, disabled ones included, sorted by number: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 12}}`. Each item has `domain_uuid`, `number`, `destination_type`, `target` and `enabled` (boolean).

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

Update an inbound destination and return it as `destination-list` does. Fields left out don't change; at least one is required (`400 {"error": "nothing to update"}` otherwise).

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

List domains, sorted by name: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 2}}`. Each item has `domain_uuid`, `domain_name` and `domain_enabled` (boolean). Users with `domain_select` get every domain, disabled ones included; other users only get their own domain. `domain_uuid` doesn't narrow the list, but it is still checked as for every action (see [Domains](#domains)). A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `extension-create`

| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid`      | no  | Domain to act on. Defaults to the key user's domain |
| `extension`        | yes | Extension number to create: digits, `*`, `#`, optional leading `+` |
| `caller_id_name`   | no  | Effective, outbound and emergency caller ID name. One line, up to 255 characters |
| `caller_id_number` | no  | Effective, outbound and emergency caller ID number (digits, `*`, `#`, optional leading `+`) |
| `user_uuid`        | no  | User of the domain to link the extension to (needs `extension_user_add`) |

Create an extension and its voicemail box. Answers `201` with the extension's details, plus its SIP `password` when the key user has `extension_password`.

A number that already exists in the domain returns `409 {"error": "extension already exists"}`, a `user_uuid` outside the domain returns `404 {"error": "user not found"}`, an invalid value returns `400 {"error": "invalid <parameter>"}`, and linking a user without `extension_user_add` returns `403` with `missing_permissions`.

## `extension-delete`

| Parameter        | Required | Description |
|------------------|----------|-------------|
| `domain_uuid`    | no  | Domain to act on. Defaults to the key user's domain |
| `extension_uuid` | yes | Extension to delete |

Delete an extension and what FusionPBX's "delete extension and voicemail" deletes with it: its user links, follow-me, extension settings, the ring group destinations that dial its number or alias, and the voicemail boxes of its number and numeric alias (options, messages, greetings, copies to other boxes, and the message files on disk). Clears FusionPBX's cached directory entry. Answers `204` with no body.

An extension that doesn't exist or belongs to another domain returns `404 {"error": "extension not found"}`, and a malformed `extension_uuid` returns `400 {"error": "invalid extension_uuid"}`.

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

Update an extension. Fields left out don't change; at least one is required (`400 {"error": "nothing to update"}` otherwise). Returns the extension with the same columns as `extension-details`, and clears FusionPBX's cached directory entry so the change applies without a reload.

Each field needs the permissions of the columns it writes, as in FusionPBX's extension edit page; a missing one refuses the whole update with `403` and `missing_permissions`. An extension or user that doesn't exist in the domain returns `404` (`extension not found`, `user not found`), and an invalid value returns `400 {"error": "invalid <parameter>"}`.

## `extension-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the extensions of a domain, disabled ones included, sorted by extension number (as text): `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 42}}`. Each item has the fields of `extension-user-list`: `extension_uuid`, `extension`, `domain_uuid`, `directory_first_name`, `directory_last_name`, `emergency_caller_id_number`, `outbound_caller_id_number`, `enabled` (boolean) and `user_uuid`. An extension can be linked to several users; `user_uuid` is the one with the lowest uuid, or `null` when none is linked (use `extension-user-list` for a user's extensions). A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.


## `extension-user-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user whose extensions to list |

List the extensions linked to a user (`v_extension_users`), sorted by extension number (as text), disabled ones included, each once even if linked twice. A user can have several extensions; FusionPBX has no primary one, so the caller picks. Returns `{"data": [...]}`. Each item has `extension_uuid`, `extension`, `domain_uuid`, `directory_first_name`, `directory_last_name`, `emergency_caller_id_number`, `outbound_caller_id_number`, `enabled` (boolean) and `user_uuid`.

A user without extensions returns `{"data": []}`. A user that is not in the domain returns `404 {"error": "user not found"}`, and a malformed `user_uuid` returns `400 {"error": "invalid user_uuid"}`.


## `user-details`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user to look up |

Return one FusionPBX user, to check that a stored `domain_uuid` + `user_uuid` pair still resolves to a user: `user_uuid`, `domain_uuid`, `username` and `user_enabled` (boolean). A disabled user is returned with `user_enabled: false`. A user that doesn't exist or belongs to another domain returns `404 {"error": "user not found"}`, and a malformed `user_uuid` returns `400 {"error": "invalid user_uuid"}`. Without `user_uuid` the request is refused before the action runs, with `400 {"error": {"error": "missing required parameter(s)", "missing_parameters": ["user_uuid"]}}` like every action's missing parameters. The uuid may be in upper or lower case.

## `user-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the FusionPBX users of a domain, disabled ones included, sorted by username: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 3}}`. Each item has `user_uuid`, `domain_uuid`, `username` and `user_enabled` (boolean); passwords and API keys are never returned. A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `recording-details`
| Parameter      | Required | Description |
|----------------|----------|-------------|
| `domain_uuid`  | no  | Domain to act on. Defaults to the key user's domain |
| `recording_id` | yes | The recorded call leg's `xml_cdr_uuid` |

Return a call recording's metadata: `recording_id`, `domain_uuid`, `filename` (the leg's `record_name`), `duration` (seconds), `xml_cdr_uuid` and `created` (the leg's `start_stamp`). As in FusionPBX's Call Recordings app, a recording is a call leg with a recording file, other than the ring group legs that lost the race (`LOSE_RACE`), and its id is the leg's `xml_cdr_uuid`. A leg that doesn't exist, belongs to another domain or wasn't recorded returns `404 {"error": "recording not found"}`, and a malformed id returns `400 {"error": "invalid recording_id"}`.

## `recording-download`
| Parameter      | Required | Description |
|----------------|----------|-------------|
| `domain_uuid`  | no  | Domain to act on. Defaults to the key user's domain |
| `recording_id` | yes | The recorded call leg's `xml_cdr_uuid`, as in `recording-details` |

Download a call recording. Unlike every other action, the response is the audio file itself, not JSON: `200` with `Content-Type` `audio/wav`, `audio/mpeg` (mp3) or `application/octet-stream`, `Content-Length`, and `Content-Disposition: attachment; filename="..."`. The file is streamed, so its size isn't limited by PHP's memory.

The file is read at the leg's `record_path`/`record_name`, as FusionPBX's Call Recordings app does, and only if it is inside FusionPBX's recordings directory (the `switch` → `recordings` default setting, `/var/lib/freeswitch/recordings` when unset); a path outside it, links included, is logged and answers `404`. Errors are JSON as for every action: `404 {"error": "recording not found"}` when the leg doesn't exist, belongs to another domain, wasn't recorded or its file is missing, `400` for a malformed id, and `500` when the file can't be read. Recordings that FusionPBX stores in the database (`call_recordings` → `storage_type` `base64`) aren't supported.

## `ringgroup-create`
| Parameter      | Required | Description |
|----------------|----------|-------------|
| `domain_uuid`  | no  | Domain to act on. Defaults to the key user's domain |
| `name`         | yes | Name of the ring group. One line, up to 255 characters |
| `extension`    | yes | Extension that routes to the ring group: digits, `*`, `#`, optional leading `+` |
| `destinations` | yes | JSON array of the numbers to ring, e.g. `[{"number": "100"}, {"number": "101"}]`. The same array encoded as a JSON string is still accepted |
| `strategy`     | yes | `simultaneous`, `sequence`, `enterprise`, `rollover` or `random` |

Create a ring group with its destinations (no delay, 30 s timeout each) and its dialplan. Answers `201` with the ring group as `ringgroup-details` returns it. A number given twice rings once.

An extension that already has a ring group in the domain returns `409 {"error": "ring group already exists"}`, and an invalid value returns `400 {"error": "invalid <parameter>"}`.

## `ringgroup-delete`
| Parameter         | Required | Description |
|-------------------|----------|-------------|
| `domain_uuid`     | no  | Domain to act on. Defaults to the key user's domain |
| `ring_group_uuid` | yes | Ring group to delete |

Delete a ring group with its users, destinations, dialplan and dialplan details, as FusionPBX's ring groups page does, and clear the dialplan cache of its context. Answers `204` with no body. A ring group that doesn't exist or belongs to another domain returns `404 {"error": "ring group not found"}`, and a malformed `ring_group_uuid` returns `400 {"error": "invalid ring_group_uuid"}`.

## `ringgroup-details`
| Parameter         | Required | Description |
|-------------------|----------|-------------|
| `domain_uuid`     | no  | Domain to act on. Defaults to the key user's domain |
| `ring_group_uuid` | yes | Ring group to look up |

Return one ring group, enabled or not, as `ringgroup-list` returns it: `ring_group_uuid`, `domain_uuid`, `name`, `extension`, `strategy` and `destinations` in the order FusionPBX shows them. A ring group that doesn't exist or belongs to another domain returns `404 {"error": "ring group not found"}`, and a malformed `ring_group_uuid` returns `400 {"error": "invalid ring_group_uuid"}`.

## `ringgroup-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `page`        | no | Page number, from 1 (default 1, at most 1000000) |
| `per_page`    | no | Rows per page, 1 to 200 (default 25) |

List the ring groups of a domain, disabled ones included, sorted by extension: `{"data": [...], "pagination": {"page": 1, "per_page": 25, "total": 3}}`. Each item has `ring_group_uuid`, `domain_uuid`, `name`, `extension`, `strategy` and `destinations` (`[{"number": "101"}, ...]`, in the order FusionPBX shows them: by delay, then number; disabled destinations, which FusionPBX doesn't ring, are left out). A page past the last returns `"data": []` with the correct `total`, and an invalid `page` or `per_page` returns `400 {"error": "invalid <parameter>"}`.

## `ringgroup-update`
| Parameter         | Required | Description |
|-------------------|----------|-------------|
| `domain_uuid`     | no  | Domain to act on. Defaults to the key user's domain |
| `ring_group_uuid` | yes | Ring group to update |
| `name`            | no  | New name. One line, up to 255 characters |
| `strategy`        | no  | `simultaneous`, `sequence`, `enterprise`, `rollover` or `random` |
| `destinations`    | no  | JSON array of the numbers to ring, e.g. `[{"number": "101"}, {"number": "102"}]` |

Update a ring group and return it as `ringgroup-details` does. Fields left out don't change; at least one is required (`400 {"error": "nothing to update"}` otherwise). The extension can't be changed. A new name is also written to the ring group's dialplan. The dialplan cache is cleared as FusionPBX's ring group page does.

`destinations` sets which numbers ring. FusionPBX rings them by delay, then number, and the list carries only numbers, so numbers already in the ring group keep their delay, timeout and other settings (a disabled one, which FusionPBX doesn't ring, is replaced by an enabled one), new ones get `ringgroup-create`'s defaults (no delay, 30 s timeout), and numbers left out are removed. The order of the list doesn't change the ringing order.

A missing permission returns `403` with `missing_permissions`, a ring group that doesn't exist or belongs to another domain returns `404 {"error": "ring group not found"}`, and an invalid value returns `400 {"error": "invalid <parameter>"}`.

## `originate`
| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `caller_id_number` | yes      | caller ID number to display for both legs of the call |
| `caller_id_name`   | no       | an optional caller ID name to request. typically will be delivered for internal calls and stripped by the upstream provider for external calls |
| `destination_a`    | yes      | the number to call first  |
| `destination_b`    | yes      | the number to call second |

Call one number (destination_a) and connect the call to another number (destination_b) when it's picked up. The selected domain's internal dialplan is used, so internal extensions may be dialed. Numbers are digits, `*`, `#` and an optional leading `+`.

Answers `201` with the call as `call-list` returns it (`call_uuid`, `domain_uuid`, `state`, `caller_id_number`, `destination_number`), once destination_a has answered. `call_uuid` is the leg that rings destination_a and bridges destination_b; it carries the domain, so `call-hangup`, `call-hold`, `call-resume` and `call-transfer` accept it.

When FreeSWITCH can't place the call it returns `500` with its reason, e.g. `{"error": "call failed: NO_ANSWER"}`, and `500 {"error": "event socket error"}` when the event socket can't be reached. An invalid number returns `400 {"error": "invalid <parameter>"}`.

Note that the call is ended when destination_a ends the call, so if one leg isn't expected to hang up, make it destination_b.

Use `destination_b=*9664` to indefinitely play hold music to destination_a.

## `call-answer`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The ringing call's channel uuid, as `call-list` returns it |

Answer a ringing call (`uuid_answer`) and return it as `call-list` does, read again after the answer. A call uuid is global to FreeSWITCH, so the channel's domain (its `domain_uuid` variable) is checked first: a call of another domain, a channel without a domain or a call that doesn't exist returns `404 {"error": "call not found"}` and is not touched. A malformed uuid returns `400 {"error": "invalid call_uuid"}`. When the event socket can't be reached or FreeSWITCH refuses, it returns `500 {"error": "event socket error"}` and logs the reason.

FusionPBX has no permission for answering a call, so the plugin adds `rest_api_call_control`, given to the superadmin and admin groups by Upgrade → Permission Defaults. Holding and resuming a call use it too.

## `call-hangup`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The call's channel uuid, as `call-list` returns it |

Hang up a call (`uuid_kill`, as FusionPBX's active calls page does). Answers `204` with no body. The call's domain is checked first, as in `call-answer`: a call of another domain or one that doesn't exist returns `404 {"error": "call not found"}` and is not touched, and a malformed uuid returns `400 {"error": "invalid call_uuid"}`. When the event socket can't be reached or FreeSWITCH refuses, it returns `500 {"error": "event socket error"}` and logs the reason.

## `call-hold`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The call's channel uuid, as `call-list` returns it |

Put an answered call on hold (`uuid_hold`, never toggled), so the other party hears the hold music, and return it as `call-list` does, with `state` `held`. Holding a call that is already held changes nothing. The call's domain is checked first, as in `call-answer`: a call of another domain or one that doesn't exist returns `404 {"error": "call not found"}` and is not touched, and a malformed uuid returns `400 {"error": "invalid call_uuid"}`. When the event socket can't be reached or FreeSWITCH refuses (e.g. the call isn't answered yet), it returns `500 {"error": "event socket error"}` and logs the reason.

## `call-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |
| `extension`   | no | Only the calls of this extension: it called, was called, or is the channel's presence (e.g. a ring group ringing it) |

List the active calls of a domain from FreeSWITCH (`show channels`), one item per channel: `{"data": [{"call_uuid", "domain_uuid", "state", "caller_id_number", "destination_number"}, ...]}`. A channel's domain is decided as FusionPBX's active calls page does: its context (the part after `@`, if any) unless that is `public` or `default`, otherwise the domain of its presence id.

`state` comes from the channel's call state: `ringing` (`DOWN`, `DIALING`, `RINGING`, `EARLY`, `RING_WAIT`, or any state FreeSWITCH adds later), `answered` (`ACTIVE`, `UNHELD`), `held` (`HELD`), `ended` (`HANGUP`), and `bridged` for an answered channel that `show calls` pairs with another leg.

An invalid `extension` returns `400 {"error": "invalid extension"}`. When the event socket can't be reached or FreeSWITCH doesn't answer with JSON, it returns `500 {"error": "event socket error"}` and logs the reason.

## `call-resume`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The held call's channel uuid, as `call-list` returns it |

Take a held call off hold (`uuid_hold off`) and return it as `call-list` does, with `state` `answered` or `bridged`. Resuming a call that isn't held changes nothing. The call's domain is checked first, as in `call-answer`: a call of another domain or one that doesn't exist returns `404 {"error": "call not found"}` and is not touched, and a malformed uuid returns `400 {"error": "invalid call_uuid"}`. When the event socket can't be reached or FreeSWITCH refuses, it returns `500 {"error": "event socket error"}` and logs the reason.

## `call-transfer`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The agent's call, as `call-list` returns it |
| `target_type` | yes | `extension`, `ring_group` or `queue` |
| `target`      | yes | The extension's number or alias, or the ring group's or queue's uuid, in the domain |

Blind-transfer a call to an extension, ring group or call center queue of the domain (`uuid_transfer <call> [-bleg] <number> XML <context>`, the number and context of the target as FusionPBX routes to it). When the call is bridged, the other party is transferred (`-bleg`, as FusionPBX's active calls page parks a call) and the agent's leg is left to end; otherwise the channel itself is transferred.

Returns the transferred leg as `call-list` does, read again after the transfer, or with `state` `ended` and no numbers if it is already gone. The call's domain is checked first, as in `call-answer`. A call of another domain or one that doesn't exist returns `404 {"error": "call not found"}`, a target that isn't in the domain `404 {"error": "target not found"}`, and an unknown `target_type` or invalid target (numbers: digits, `*`, `#`, optional leading `+`; ring groups and queues: uuids) `400`. When the event socket can't be reached or FreeSWITCH refuses, it returns `500 {"error": "event socket error"}` and logs the reason.

## `call-transfer-attended`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `call_uuid`   | yes | The agent's call, bridged to the caller, as `call-list` returns it |
| `stage`       | yes | `consult`, `cancel` or `complete` |
| `target`      | with `consult` | Number to consult (digits, `*`, `#`, optional leading `+`), dialed through the domain's dialplan |

Warm (attended) transfer with FreeSWITCH's `att_xfer`, run on the agent's leg:

* `consult`: the caller is put on hold with music and `target` is called from the agent's leg. The call must be bridged (`400 {"error": "call is not bridged"}`), and only one consultation runs at a time (`400 {"error": "consultation already in progress"}`). Returns the agent's call.
* `cancel`: the consultation is hung up and the agent is back with the caller. Returns the agent's call.
* `complete`: the agent's leg is hung up and the caller is bridged to the consulted party. Returns the caller's leg (`state` `ended` if it is already gone).

The consultation is tracked on the agent's channel itself (the channel variables `rest_api_consult_uuid` and `rest_api_consult_caller`), so nothing is kept between requests; `cancel` or `complete` without a consultation in progress returns `400 {"error": "no consultation in progress"}`. The call's domain is checked first, as in `call-answer`, and the usual `400`, `404` and `500` apply.

**Not yet verified on a real call.** The commands follow FreeSWITCH's documentation of `att_xfer` (`uuid_broadcast <agent> att_xfer::{origination_uuid=<uuid>}loopback/<target>/<domain> aleg`, then `uuid_kill` of the consult leg or of the agent's leg); try a warm transfer on a FusionPBX 5.6.5 test system before relying on it.

## `callcenter-agent-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |

List the call center agents of a domain, sorted by agent name: `{"data": [...]}`, not paginated. Each item has `user_uuid` (the agent's FusionPBX user), `queues` (the queues the agent serves, `[{"call_center_queue_uuid", "level", "position"}]`, by tier level then position) and `wrap_up_time` (seconds, or `null` when not set). FusionPBX agents without a user are left out, as agents are identified by `user_uuid`. A domain without agents returns `{"data": []}`.

## `callcenter-agent-state`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user whose call center agent to change |
| `state`       | yes | `Waiting`, `In a queue call`, `Receiving a call` or `Wrap-up` |

Set the call center state of a user's agent in FreeSWITCH's mod_callcenter (`callcenter_config agent set state`), for example `Waiting` to end its wrap-up so it takes queue calls again, and return `{"user_uuid", "status", "state"}` read live as `callcenter-agent-status` does. The state only lives in mod_callcenter; FusionPBX keeps no copy of it. A user with several agents in the domain is answered for the first by agent name.

A user without an agent in the domain returns `404 {"error": "agent not found"}`, and an unknown state or malformed uuid returns `400`. When the event socket can't be reached or FreeSWITCH refuses the command, it returns `500 {"error": "event socket error"}` and logs the reason.

## `callcenter-agent-status`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no  | Domain to act on. Defaults to the key user's domain |
| `user_uuid`   | yes | FusionPBX user whose call center agent to read or change |
| `status`      | no  | New status: `Available`, `Available (On Demand)`, `On Break` or `Logged Out`. Without it the status is only read |

Read, or set, the call center status of a user's agent: `{"user_uuid", "status", "state"}`, both read live from FreeSWITCH's mod_callcenter through the event socket (`state` is `Waiting`, `In a queue call`, `Receiving a call`, `Wrap-up`...). A user with several agents in the domain is answered for the first by agent name.

Setting runs the commands of FusionPBX's agent status page (`callcenter_config agent set status`, and `agent set state ... 'Waiting'` after `Available` or `Logged Out`) and saves the status on the agent, so FreeSWITCH keeps it after a restart. It doesn't change the user's own status (`user_status`) or the BLF lamps the page also updates.

A user without an agent in the domain returns `404 {"error": "agent not found"}`, an unknown status or malformed uuid returns `400`, and setting without `call_center_agent_edit` returns `403`. When the event socket can't be reached or FreeSWITCH refuses a command, it returns `500 {"error": "event socket error"}` and logs the reason; a refused status isn't saved.

## `callcenter-queue-list`
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no | Domain to act on. Defaults to the key user's domain |

List the call center queues of a domain, sorted by extension: `{"data": [...]}`, not paginated. Each item has `call_center_queue_uuid`, `name`, `extension`, `strategy` (mod_callcenter's, e.g. `ring-all`) and `queue_tier_rules_wait_second` (FusionPBX's "tier rule wait second", an integer, or `null` when it isn't set). A domain without queues returns `{"data": []}`.

## `callcenter-queue-status`
| Parameter                | Required | Description |
|--------------------------|----------|-------------|
| `domain_uuid`            | no  | Domain to act on. Defaults to the key user's domain |
| `call_center_queue_uuid` | yes | Queue to look at |

Live counts of a call center queue, read from FreeSWITCH's mod_callcenter through the event socket (`callcenter_config queue list members|agents <extension>@<domain>`, as FusionPBX's Active Call Center page does): `call_center_queue_uuid`, `waiting_calls` (calls waiting for an agent), `member_count` (every call in the queue, waiting or with an agent) and `agent_count` (agents assigned to the queue, whatever their status). A queue that doesn't exist or belongs to another domain returns `404 {"error": "queue not found"}`, and a malformed uuid returns `400 {"error": "invalid call_center_queue_uuid"}`. When the event socket can't be reached or FreeSWITCH refuses the command, it returns `500 {"error": "event socket error"}` and logs the reason.

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

The in-memory database only shows that the plugin's SQL does what it should, not that PostgreSQL accepts it. The `pgsql` suite (`tests/Pgsql`) runs the `cdr-search`, `cdr-details` and `recording-details` tests, and the App Defaults index, on a real PostgreSQL with FusionPBX 5.6.5's `v_xml_cdr` columns, through PDO the way FusionPBX's `database` class uses it. It needs Docker:

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
