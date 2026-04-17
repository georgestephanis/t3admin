# Temporary Titan Token

> Hold the title of titan, if only for a tick.

A WordPress plugin that lets administrators temporarily replace a user's effective role set, with automatic expiry, real-time enforcement, and a full audit trail.

[![Open in WordPress Playground](https://img.shields.io/badge/Open_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fgeorgestephanis%2Ft3admin%2Ftrunk%2F.github%2Fblueprint.json)

---

## Features

- **Temporary role replacement** — temporarily assign any exact role set, whether that means promotion, demotion, or no roles at all.
- **Flexible expiry** — choose a specific date and time (interpreted in the site's timezone), or a simple duration (N minutes, hours, or days from now).
- **Automatic expiry** — when the window closes, temporary capability overlay ends automatically. No manual cleanup required.
- **Two-layer expiry enforcement**
  - A per-grant `wp_schedule_single_event()` fires at the exact expiry timestamp.
  - A `user_has_cap` filter acts as a catch-all: even if the scheduled event was missed (low-traffic site, cron backlog) the elevated permission is revoked the moment the user makes any capability check.
- **Scoped superseding** — a new grant supersedes existing active grants for the same scope target.
- **Manual revocation** — admins can revoke any active grant instantly from the admin UI.
- **JSONL audit log** — every grant, revocation, and automatic expiry is appended to `wp-content/uploads/t3admin-logs/access-grants.jsonl`. The directory is protected from direct HTTP access via `.htaccess` (Apache) and `web.config` (IIS).
- **In-admin log viewer** — paginated log table on the **Access Logs** tab inside **Users → Temp Roles**.
- **Multisite-aware scopes** — site admins can grant temporary access for their current site; network admins can grant single-site, whole-network, or temporary super-admin access.
- **Adaptive user picker** — small sites get a direct user dropdown; larger sites and Network Admin use live search by display name, login, or email.
- **WP-CLI support** — grant or revoke temporary role changes from the command line, including exact role sets and remove-all mode.
- **Fully internationalised** — all strings are wrapped with i18n functions and the `t3admin` text domain.

---

## Requirements

|           | Minimum |
| --------- | ------- |
| WordPress | 6.0     |
| PHP       | 7.4     |

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

### Granting a temporary role change

1. Go to **Users → Temp Roles** in wp-admin.
2. Choose a **User**:

- Small sites show the full dropdown directly.
- Larger sites and Network Admin show a live search field that loads matching users.

3. Choose the **Temporary Role** to grant.
4. On Multisite Network Admin, choose **Grant Scope**:

- **Single Site**
- **Whole Network**
- **Super Admin (Temporary)**

5. Set the **Expiry Type**:
   - **Specific date & time** — pick a datetime (the site's configured timezone applies).
   - **Duration from now** — enter a number and choose Minutes, Hours, or Days.
6. Click **Grant Temporary Role**.

The user's original role set is recorded for audit context, and the temporary role set takes effect immediately through capability filtering.

Selecting a lower role now behaves as a real temporary demotion. For example, changing an `editor` to `subscriber` temporarily removes editor capabilities until the grant expires.

### Revoking a grant early

In the **Active Grants** table on the same page, click **Revoke** next to any row. Temporary capability overlay ends immediately.

### Viewing the audit log

Open the **Access Logs** tab inside **Users → Temp Roles**. Log entries are shown newest-first, 50 per page, and include:

| Column      | Description                                                |
| ----------- | ---------------------------------------------------------- |
| Timestamp   | ISO 8601 UTC timestamp of the event                        |
| Event       | `granted`, `revoked`, or `expired`                         |
| User        | Display name of the elevated user                          |
| Role Change | `original_role → temporary_role`                           |
| Actor       | Admin who granted or revoked (System for automatic expiry) |
| Expires At  | Scheduled expiry time                                      |

The raw JSONL file is at `wp-content/uploads/t3admin-logs/access-grants.jsonl` and is blocked from direct web access on Apache and IIS.

If you run Nginx, add an explicit deny rule for `/wp-content/uploads/t3admin-logs/` in your server config.

---

## How expiry works

When a grant is created:

1. The user's current role slug is saved in the grant record.
2. `wp_schedule_single_event()` is called to fire `t3admin_expire_grant` at the exact expiry Unix timestamp.
3. The `user_has_cap` filter overlays temporary capabilities while the grant remains active and in-scope.

At expiry:

- The scheduled event calls `expire_grant()`, which marks the grant as `expired` in storage and stops capability overlay.
- Additionally, a `user_has_cap` filter runs on every request. If it detects an active grant whose `expires_at` is in the past it expires the grant inline — so even a site with broken WP-Cron cannot keep a temporary role change alive past its window.

## WP-CLI

Use the `t3admin` command to create or revoke temporary grants from the command line.

```bash
wp t3admin grant alice --role=subscriber --duration="2 days"
wp t3admin grant bob --roles=subscriber,author --expires="2026-04-20 17:00"
wp t3admin grant carol --remove-roles=editor --duration="1 day"
wp t3admin grant dave --remove-all-roles --duration="12 hours"
wp t3admin revoke <grant-uuid>
```

Notes:

- `--role` and `--roles` set the exact temporary role set.
- `--remove-roles` derives a temporary site-scoped role set by removing specific roles from the user's current site roles.
- `--remove-all-roles` temporarily strips all roles while leaving any direct user-specific capabilities intact.
- Use either `--duration` or `--expires`, but not both.

### Common scenarios

1. Temporarily demote an editor to subscriber for two days:

  wp t3admin grant alice --role=subscriber --duration="2 days"

2. Temporarily suspend a user by removing all roles for 12 hours:

  wp t3admin grant alice --remove-all-roles --duration="12 hours"

3. Temporarily remove only one role from a multi-role user:

  wp t3admin grant alice --remove-roles=editor --duration="1 day"

4. Temporarily assign an exact multi-role set:

  wp t3admin grant alice --roles=subscriber,author --duration="3 days"

5. End a temporary change immediately:

  wp t3admin revoke <grant-uuid>

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

There are no automated tests at this time. See `AGENTS.md` for manual smoke-test commands using WP-CLI.

---

## Audit log format

Each line of `access-grants.jsonl` is a self-contained JSON object. Example:

```json
{"timestamp":"2026-04-16T14:23:01+00:00","event":"granted","grant_id":"a1b2c3...","user_id":5,"original_role":"subscriber","temporary_role":"editor","granted_by":1,"granted_at":1744813381,"expires_at":1744816981}
{"timestamp":"2026-04-16T15:23:05+00:00","event":"expired","grant_id":"a1b2c3...","user_id":5,"original_role":"subscriber","temporary_role":"editor","granted_by":1,"granted_at":1744813381,"expires_at":1744816981,"resolved_at":1744816985}
```

Fields present on all events:

| Field             | Type   | Description                             |
| ----------------- | ------ | --------------------------------------- |
| `timestamp`       | string | ISO 8601 UTC                            |
| `event`           | string | `granted` \| `revoked` \| `expired`     |
| `grant_id`        | string | UUID v4                                 |
| `user_id`         | int    | Elevated user                           |
| `original_roles`  | array  | Roles before the temporary change       |
| `temporary_roles` | array  | Roles during the temporary change       |
| `original_role`   | string | First original role, for compatibility  |
| `temporary_role`  | string | First temporary role, for compatibility |
| `granted_by`      | int    | Admin user ID                           |
| `granted_at`      | int    | Unix timestamp                          |
| `expires_at`      | int    | Unix timestamp                          |

Additional fields on `revoked` / `expired` events:

| Field           | Type   | Description                               |
| --------------- | ------ | ----------------------------------------- |
| `resolved_at`   | int    | Unix timestamp of resolution              |
| `revoked_by`    | int    | Admin user ID (`revoked` only)            |
| `revoke_reason` | string | `manual` or `superseded` (`revoked` only) |

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
