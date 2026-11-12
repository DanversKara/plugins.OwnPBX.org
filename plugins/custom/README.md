# OwnPBX custom plugins

Plugins written for (or modified for) OwnPBX. They are kept apart from `plugins/community/`, which
mirrors upstream ([mahirgul/aipbx-plugins](https://github.com/mahirgul/aipbx-plugins)) untouched, so
an upstream release can always be compared against our versions.

| Plugin | Slug | Version | Kind | What it does |
| :--- | :--- | :--- | :--- | :--- |
| **Home Assistant texts** | [`ha-texts`](ha-texts/) | 1.0.0 | Own | Lets Home Assistant (and the ESP32-S3-Box-3 "Messages" app) read and send PBX texts/chat: `/api/plugins/ha-texts/messages/inbox` and `/send`, token protected. Includes the HA package ([`home-assistant/`](ha-texts/home-assistant/)) and the box firmware ([`esp32/`](ha-texts/esp32/)). |
| **Invites** | [`invites`](invites/) | 1.0.0 | Own | Admin-made invite links (single or multi-use) that create an extension/user on the spot; copy link or e-mail it. |
| **Billing / store** | [`billing`](billing/) | 1.0.1 | Own | Monthly Stripe plans that set each user's outside-call minutes, texts and fax; a public Store whose sign-ups wait for approval; limits can be watched before they are enforced. |
| **SMS / MMS** | [`sms`](sms/) | 1.3.0 | Modified from upstream `sms` 1.2.2 | Upstream SMS plugin plus: unread badges per number (`user/badge.php`), Edit button for numbers, the provider list defaults to a configured provider, OwnPBX naming. |

## Where they run

The same code ships inside the OwnPBX repository (`plugins/<slug>/`), so every OwnPBX update
carries it. This folder is the plugin's home for review and upgrades; when one changes, both copies
are updated together.

## Upstream updates

[`UPSTREAM.md`](../../UPSTREAM.md) records which upstream commit our copies are based on.
`scripts/upstream-check.sh` fetches upstream and shows what changed since then, and for `sms` how
upstream's newer version differs from ours, so the changes can be merged without losing ours.

## Home Assistant files

`ha-texts/home-assistant/ha_pbx_messages.yaml` is a template: replace `YOUR-PBX-HOST` with the PBX's
host name and `PASTE_TOKEN_FROM_PLUGIN_PAGE` with the token shown on the plugin's admin page. Never
commit the filled-in copy (this repository is public).
