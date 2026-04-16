# Temporary Titan Token

> Hold the title of titan, if only for a tick.

A WordPress plugin that lets administrators temporarily elevate a user's role, with automatic expiry, real-time enforcement, and a full audit trail.

[![Open in WordPress Playground](https://img.shields.io/badge/Open_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fgeorgestephanis%2Ft3admin%2Ftrunk%2F.github%2Fblueprint.json)

---

## Features

- **Temporary role elevation** — promote any user to any registered role for a set window of time.
- **Flexible expiry** — choose a specific date and time (interpreted in the site's timezone), or a simple duration (N minutes, hours, or days from now).
- **Automatic restoration** — when the window closes the user's original role is silently restored.  No manual cleanup required.
- **Two-layer expiry enforcement**
  - A per-grant `wp_schedule_single_event()` fires at the exact expiry timestamp.
  - A `user_has_cap` filter acts as a catch-all: even if the scheduled event was missed (low-traffic site, cron backlog) the elevated permission is revoked the moment the user makes any capability check.
- **Superseding grants** — granting a new temporary role to a user who already has one automatically revokes the old grant first.
- **Manual revocation** — admins can revoke any active grant instantly from the admin UI.
- **JSONL audit log** — every grant, revocation, and automatic expiry is appended to `wp-content/uploads/t3admin-logs/access-grants.jsonl`.  The file is protected from direct HTTP access via `.htaccess`.
- **In-admin log viewer** — paginated log table under **Users → Temp Role Logs**, readable without file-system access.
- **Multisite-aware** — uses the `promote_users` capability, which is held by Administrators on single sites and Super Admins on Multisite networks.
- **Fully internationalised** — all strings are wrapped with i18n functions and the `t3admin` text domain.

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |

---

## Installation

### From the WordPress admin

1. Upload the `t3admin` folder to `wp-content/plugins/`.
2. Go to **Plugins → Installed Plugins** and activate **Temporary Titan Token**.

### With WP-CLI

```bash
wp plugin install /path/to/t3admin.zip --activate
```

### From source

```bash
cd wp-content/plugins/
git clone https://github.com/georgestephanis/t3admin.git
wp plugin activate t3admin
```

---

## Usage

### Granting a temporary role

1. Go to **Users → Temp Roles** in wp-admin.
2. Select a **User** from the dropdown.
3. Choose the **Temporary Role** to grant.
4. Set the **Expiry Type**:
   - **Specific date & time** — pick a datetime (the site's configured timezone applies).
   - **Duration from now** — enter a number and choose Minutes, Hours, or Days.
5. Click **Grant Temporary Role**.

The user's current role is stored internally; the temporary role takes effect immediately.

### Revoking a grant early

In the **Active Grants** table on the same page, click **Revoke** next to any row.  The user's original role is restored instantly.

### Viewing the audit log

Click **View Access Logs** at the bottom of the grants page, or navigate to **Users → Temp Role Logs**.  Log entries are shown newest-first, 50 per page, and include:

| Column | Description |
|--------|-------------|
| Timestamp | ISO 8601 UTC timestamp of the event |
| Event | `granted`, `revoked`, or `expired` |
| User | Display name of the elevated user |
| Role Change | `original_role → temporary_role` |
| Actor | Admin who granted or revoked (System for automatic expiry) |
| Expires At | Scheduled expiry time |

The raw JSONL file is at `wp-content/uploads/t3admin-logs/access-grants.jsonl` and is blocked from direct web access.

---

## How expiry works

When a grant is created:

1. The user's current role slug is saved in the grant record.
2. The user's role is immediately changed to the temporary role.
3. `wp_schedule_single_event()` is called to fire `t3admin_expire_grant` at the exact expiry Unix timestamp.

At expiry:

- The scheduled event calls `expire_grant()`, which restores the original role and marks the grant as `expired` in the option store.
- Additionally, a `user_has_cap` filter runs on every request.  If it detects an active grant whose `expires_at` is in the past it expires the grant inline — so even a site with broken WP-Cron cannot keep an elevated role alive past its window.

---

## Development

### Prerequisites

- PHP 7.4+
- [Composer](https://getcomposer.org/)

### Setup

```bash
git clone https://github.com/georgestephanis/t3admin.git
cd t3admin
composer install
```

### Linting

```bash
composer phpcs    # check for violations
composer phpcbf   # auto-fix violations
```

The ruleset is defined in `.phpcs.xml` and targets the **WordPress** coding standard.

### Running tests

There are no automated tests at this time.  See `AGENTS.md` for manual smoke-test commands using WP-CLI.

---

## Audit log format

Each line of `access-grants.jsonl` is a self-contained JSON object.  Example:

```json
{"timestamp":"2026-04-16T14:23:01+00:00","event":"granted","grant_id":"a1b2c3...","user_id":5,"original_role":"subscriber","temporary_role":"editor","granted_by":1,"granted_at":1744813381,"expires_at":1744816981}
{"timestamp":"2026-04-16T15:23:05+00:00","event":"expired","grant_id":"a1b2c3...","user_id":5,"original_role":"subscriber","temporary_role":"editor","granted_by":1,"granted_at":1744813381,"expires_at":1744816981,"resolved_at":1744816985}
```

Fields present on all events:

| Field | Type | Description |
|-------|------|-------------|
| `timestamp` | string | ISO 8601 UTC |
| `event` | string | `granted` \| `revoked` \| `expired` |
| `grant_id` | string | UUID v4 |
| `user_id` | int | Elevated user |
| `original_role` | string | Role before elevation |
| `temporary_role` | string | Role during elevation |
| `granted_by` | int | Admin user ID |
| `granted_at` | int | Unix timestamp |
| `expires_at` | int | Unix timestamp |

Additional fields on `revoked` / `expired` events:

| Field | Type | Description |
|-------|------|-------------|
| `resolved_at` | int | Unix timestamp of resolution |
| `revoked_by` | int | Admin user ID (`revoked` only) |
| `revoke_reason` | string | `manual` or `superseded` (`revoked` only) |

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
