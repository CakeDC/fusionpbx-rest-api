# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each release targets the FusionPBX version given in the README's Compatibility section.

## [Unreleased]

### Changed

- `callcenter-queue-status`, `callcenter-agent-status` and `callcenter-agent-state` answer `500` `{"error": "mod_callcenter not loaded"}` instead of `event socket error` when FreeSWITCH runs without mod_callcenter (`-ERR callcenter_config Command not found!`), so a missing module can be told apart from an event socket that can't be reached. The log line of `callcenter-queue-status` now names the failed command.
- `callcenter-agent-status` and `callcenter-agent-state` answer `500` `{"error": "agent not loaded in mod_callcenter"}` instead of `event socket error` for an agent that mod_callcenter hasn't loaded: missing from `callcenter_config agent list`, or `agent set` answering `-ERR Invalid Agent!`. Agents written to the database are only loaded by mod_callcenter on a save in FusionPBX's UI or a reload.

### Fixed

- `callcenter-agent-status` with `status` didn't save the status when FreeSWITCH took it but refused the `agent set state ... 'Waiting'` that follows `Available` and `Logged Out`, so the agent's row no longer matched mod_callcenter and a restart brought the old status back. The status is now saved as soon as FreeSWITCH takes it.
- `call-list` with `extension` listed a call between two extensions by the caller's leg for both of them when the called extension's leg had no context or presence of the domain, so `call-transfer` moved the agent instead of the caller and `call-transfer-attended` consulted from the caller's phone. Such a leg now takes the domain of the leg it is paired with, and a call is listed by the leg of the extension's own phone (its presence, or its SIP user on a profile other than a gateway or an `external` one).
- `call-list` listed a ringing call once per leg until it was answered. A leg placed by another (its `call_uuid`) is now part of that leg's call, and so is the consult leg of `call-transfer-attended`, which stays part of the agent's call.
- `call-list` with `extension` listed a call by whichever of its legs came first in `show channels` when several fit the extension equally (a consult leg named after the agent's SIP user, a gateway leg that took the extension's presence, a phone ringing on two registrations), so a call action could act on a different leg from one request to the next. The leg nearest the call's first leg is now listed, then the lowest `call_uuid`.
- `call-answer`, `call-hangup`, `call-hold`, `call-resume`, `call-transfer` and `call-transfer-attended` answered `404` `call not found` for the leg of the extension called, which has only the `domain_name` variable the dialplan exports: a leg without `domain_uuid` is now checked by its `domain_name`.
- The `call_uuid` that `originate` returned disappeared once the call was answered (the loopback channel bowed out), so the call actions answered `404` for it. An extension of the domain given as `destination_a` (its number, preferred over another extension's alias, or its alias) is now called directly (`user/`), and any other `destination_a` through a loopback that doesn't bow out. destination_a's phone now shows `caller_id_number` (and `caller_id_name`) too. The extension's call forwarding, do not disturb, follow me and voicemail on no answer no longer apply to destination_a, and an extension that isn't registered fails the call with `500` `call failed: USER_NOT_REGISTERED`.

## [2.0.1] - 2026-10-19

Targets FusionPBX **5.6.5**. Bug fixes only; no Upgrade step beyond the usual ones is needed.

### Fixed

- `call-transfer` and `call-transfer-attended` answered `403` to every key: they required `call_active_transfer`, which FusionPBX 5.6.5 doesn't define, so no group could hold it. They now need `rest_api_call_control`, like `call-answer`, `call-hold` and `call-resume`. The `consult` stage of `call-transfer-attended` also needs `click_to_call_call`, as `originate` does: it dials any number the domain routes, outbound ones included.
- `extension-update` saved the change but answered `500` `database error`, `extension-create` answered `500` and `extension-details` `404`: the returned columns included `forward_caller_id_uuid`, which FusionPBX 5.6.5's `v_extensions` doesn't have, so PostgreSQL refused the query. The column is no longer returned.
- `extension-create` and `extension-update` now log the database error (`rest_api: ... reading it back failed`) when the extension is saved but reading it back fails, so the `500` they answer can be traced although the change is applied.
- `extension-details` answered `404` `extension not found` when the database query failed: it now answers `500` `database error`, and `400` `invalid extension_uuid` for a malformed uuid.

## [2.0.0] - 2026-10-14

Targets FusionPBX **5.6.5**. Contains breaking changes: read [Upgrading from earlier versions](README.md#upgrading-from-earlier-versions) before updating, and run Advanced → Upgrade → Permission Defaults afterwards.

### Added

- Domains and users:
  - `domain-list`: list domains (every domain with `domain_select`, otherwise the user's own).
  - `user-list`: list the users of a domain, paginated.
  - `user-details`: look up one user, to check that a stored `domain_uuid` + `user_uuid` pair still resolves.
- Extensions:
  - `extension-update`: change the caller ID name and number, `enabled` and the linked user, with FusionPBX's per-field permissions.
  - `extension-delete`: delete an extension with its voicemail, follow-me, settings, user links and ring group destinations, as FusionPBX does.
- Inbound destinations:
  - `destination-list`: list a domain's inbound destinations, with the type (`extension`, `ring_group`, `ivr`, `voicemail`) and target each one routes to.
  - `destination-update`: change a destination's target or `enabled`, keeping the rest of its dialplan.
  - `destination-delete`: delete a destination with its dialplan.
- Ring groups:
  - `ringgroup-list` and `ringgroup-details`: ring groups with their destinations, in FusionPBX's ringing order.
  - `ringgroup-update`: change the name, strategy and destinations.
  - `ringgroup-delete`: delete a ring group with its users, destinations and dialplan.
- Call center (live data read from FreeSWITCH's mod_callcenter):
  - `callcenter-queue-list`: list call center queues.
  - `callcenter-queue-status`: live queue counts (waiting calls, longest wait, members, agents, available agents).
  - `callcenter-agent-list`: list agents with their queues and wrap-up time.
  - `callcenter-agent-status`: read or set an agent's status, with its live state and the end of its wrap-up time.
  - `callcenter-agent-state`: set an agent's state, e.g. `Waiting` to end wrap-up early.
- Call recordings:
  - `recording-details`: metadata of a call recording.
  - `recording-download`: download the recording file, streamed (the only action whose response isn't JSON).
- Active calls (through FreeSWITCH's event socket, each call checked against the request's domain first):
  - `call-list`: list a domain's active calls, optionally for some extensions only.
  - `call-answer`, `call-hangup`, `call-hold`, `call-resume`.
  - `call-transfer`: blind transfer to an extension, ring group or call center queue.
  - `call-transfer-attended`: warm transfer in three stages (`consult`, `cancel`, `complete`). Not yet verified on a real call.
- The `rest_api_call_control` permission, given to the superadmin and admin groups by Permission Defaults. FusionPBX has no permission to answer, hold or resume a call; `call-answer`, `call-hold` and `call-resume` need this one.
- `extension-create` takes an optional `user_uuid` to link the new extension to a user (needs `extension_user_add`).
- `ringgroup-create` accepts `destinations` as a JSON array (a JSON-encoded string is still accepted).
- Actions can answer `204` without a body, or with a file streamed by `rest.php`.

### Changed

- **Breaking:** `originate` calls `destination_a` first, as documented (it called `destination_b` first), and answers `201` with the call as `call-list` returns it instead of `{"success": ..., "call_uuid": ...}`. A call FreeSWITCH can't place answers `500` instead of `200` with `success: false`.
- **Breaking:** `extension-list` returns `{"data": [...], "pagination": {...}}` instead of a bare array, 25 extensions per page by default (up to 200 with `per_page`), with the same fields as `extension-user-list`.
- **Breaking:** `extension-create` answers `201` instead of `200`. An existing number answers `409` instead of `500`, and an invalid `extension` or caller ID answers `400` instead of being saved.
- **Breaking:** `ringgroup-create` answers `201` with the ring group as `ringgroup-details` returns it, instead of `200` with FusionPBX's columns. An existing extension answers `409` instead of `500`, and invalid destinations answer `400 {"error": "invalid destinations"}`.
- **Breaking:** `extension-create` and `extension-details` return `enabled` as a JSON boolean on every database (it was `"true"`/`"false"` text on sqlite and mysql).
- **Breaking:** `destination-details` answers `409 {"error": "ambiguous destination"}` when the number matches several destinations, instead of returning one of them.
- `extension-user-list` accepts an upper-case `user_uuid`.

### Fixed

- `destination-details` answers `500` on a database error instead of `404`.

## [1.0.0] - 2026-10-07

First release. Targets FusionPBX **5.6.5**.

### Added

- API keys bound to a FusionPBX user: each request runs as that user, with its groups' permissions and its domain. Keys are managed in the FusionPBX menu (`rest_api_key_*` permissions), and the secret is shown once and stored hashed.
- Every action declares the FusionPBX permissions it needs; a missing one answers `403` with `missing_permissions`.
- `domain_uuid` is optional and defaults to the key user's domain. Other domains need `domain_select`.
- `extension-user-list`: the extensions linked to a user.
- `cdr-search` (filtered, paginated call history, one row per call or per leg) and `cdr-details` (a call with all its legs). App Defaults adds the index on `v_xml_cdr.originating_leg_uuid` they need on PostgreSQL.
- Actions from the original code: `cdr-list`, `destination-create`, `destination-details`, `domain-details`, `extension-create`, `extension-details`, `extension-list`, `originate`, `ringgroup-create`.

### Changed

- Responses only contain documented columns. `extension-create` returns the SIP password only to users with `extension_password`.
- **Breaking** (from the AccelerateNetworks code): existing keys stop working until a superadmin binds each one to a user.

[Unreleased]: https://github.com/CakeDC/fusionpbx-rest-api/compare/2.0.1...HEAD
[2.0.1]: https://github.com/CakeDC/fusionpbx-rest-api/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/CakeDC/fusionpbx-rest-api/compare/1.0.0...2.0.0
[1.0.0]: https://github.com/CakeDC/fusionpbx-rest-api/releases/tag/1.0.0
