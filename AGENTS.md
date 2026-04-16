# AGENTS.md — Temporary Titan Token (t3admin)

AI coding agents working in this plugin should read this file before making changes.

## What this plugin does

Temporary Titan Token (`t3admin`) lets WordPress admins (or network super-admins on Multisite) promote any user to a higher role for a fixed window of time — either a specific date/time, or a duration from now (minutes, hours, days).  When the window closes the user's original role is automatically restored.  Every grant, revocation, and expiry is appended to a JSONL audit log.

## File structure

```
t3admin/
├── t3admin.php                         Thin loader — bootstraps sub-classes, lifecycle hooks.
├── includes/
│   ├── class-logger.php                T3Admin_Logger — JSONL audit log read/write.
│   ├── class-grants.php                T3Admin_Grants — grant storage, cap filters, expiry.
│   ├── class-log-list-table.php        T3Admin_Log_List_Table — WP_List_Table subclass.
│   └── class-admin.php                 T3Admin_Admin — menu, tabs, form handlers, Users column.
├── composer.json                       Dev-dependency manifest (WPCS, phpcs).
├── .phpcs.xml                          PHPCS configuration; run `composer phpcs` to lint.
├── AGENTS.md                           This file.
├── README.md                           GitHub readme.
└── readme.txt                          WordPress.org plugin directory readme.
```

There are no build steps and no JavaScript bundles.

## Architecture

The plugin bootstraps from `t3admin.php` which instantiates `T3Admin_Logger`, `T3Admin_Grants`, and `T3Admin_Admin`, then registers `activate` / `deactivate` hooks and defers all other hook registration to `setup()` on `init`.  Key sections:

### Data storage

Grants are stored as a single WordPress option (`t3admin_grants`) — a PHP array keyed by UUID, where each value is a grant record:

```php
array(
    'id'             => string,  // UUID v4
    'user_id'        => int,
    'original_role'  => string,  // role slug before elevation
    'temporary_role' => string,  // role slug during elevation
    'granted_by'     => int,     // admin user ID
    'granted_at'     => int,     // Unix timestamp
    'expires_at'     => int,     // Unix timestamp
    'status'         => string,  // 'active' | 'revoked' | 'expired'
    // resolved grants also carry:
    'resolved_at'    => int,
    // revoked grants also carry:
    'revoked_by'     => int,
    'revoke_reason'  => string,  // 'manual' | 'superseded'
)
```

`update_option( 't3admin_grants', $grants, false )` is called whenever the array is modified.  The `false` autoload flag prevents loading the full grant store on every page.

### Expiry mechanism (two-layer)

1. **`wp_schedule_single_event()`** — At grant time a per-grant cron event (`t3admin_expire_grant`) is scheduled for the exact expiry timestamp.  This is the primary mechanism.

2. **`user_has_cap` filter** (`enforce_expiry_on_cap_check`) — On every capability check WordPress passes the user object through this filter.  If the user holds an active-but-overdue grant the role is reverted inline.  This is the safety net: even if the cron event was missed (low-traffic site, cron backlog) the elevated permission cannot persist beyond the first request that checks capabilities.  A static `$expiring` flag prevents re-entrance because `set_role()` itself triggers another cap check.

### Audit log

Appended to `{uploads}/t3admin-logs/access-grants.jsonl`.  Each line is a complete JSON object.  The directory is created on activation with an `.htaccess` (`Require all denied`) and an `index.php` stub.  Direct `file_put_contents()` is used with `FILE_APPEND | LOCK_EX`; WP_Filesystem is not used because it requires an active admin context and is inappropriate for background writes.

### Admin UI

One page registered under **Users** in the sidebar (`t3admin`), hosting two tabs:

| Tab (`?tab=`) | Renderer | Purpose |
|---------------|----------|---------|
| `grants` (default) | `render_grants_tab()` | Grant form + active grants table |
| `logs` | `render_logs_tab()` | `T3Admin_Log_List_Table` — 50/page, newest first |

Form submissions go through `admin-post.php` (`admin_post_t3admin_grant`, `admin_post_t3admin_revoke`) and are protected by `wp_nonce_field()` / `check_admin_referer()`.

The Users list table Role column is replaced with a custom `t3admin_role` column (via `manage_users_columns`) that shows the real role and, when a temp grant is active, a second italic line with the temp role and remaining time.  Hovering shows the exact expiry timestamp.

## Capability used

`promote_users` — held by Administrators and (on Multisite) Super Admins.  No new capabilities or roles are registered.

## Coding standards

Run the linter before committing:

```bash
composer install          # first time only
composer phpcs            # lint
composer phpcbf           # auto-fix
```

The ruleset is `.phpcs.xml` (WordPress standard, filename sniff excluded for the single-file layout).  The global phpcs binary in this project may have a version mismatch with WPCS; always prefer `./vendor/bin/phpcs`.

## Key constraints for agents

- When adding new files to `includes/`, add them to the `<file>` list in `.phpcs.xml` and update the file structure section above.
- **Do not** add a recurring cron schedule.  Expiry runs through per-grant single events + the cap filter.  Adding `wp_schedule_event()` would be redundant and would require a cleanup path on deactivation.
- **Do not** use `DB_HOST`, `DB_NAME`, etc.  This site uses the SQLite drop-in; those constants are not defined.
- **Do not** use `FULLTEXT` indexes or raw `CREATE TABLE` SQL.
- All user-facing strings **must** use i18n functions (`__()`, `esc_html_e()`, etc.) with the `t3admin` text domain.
- Output in templates **must** be escaped (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`).
- `$_POST` values **must** be passed through `wp_unslash()` before sanitization.
- Prefer `wp_json_encode()` over `json_encode()`.
- Maintain Yoda conditions throughout (`'active' === $status`, not `$status === 'active'`).

## Testing a change

```bash
# Activate the plugin (if not already active)
studio wp plugin activate t3admin

# Smoke-test that the class loads
studio wp eval 'echo class_exists("Temporary_Titan_Token") ? "OK" : "FAIL";'

# Create a test user and grant a 2-minute elevation
studio wp user create testuser testuser@example.com --role=subscriber
studio wp eval '
$plugin = Temporary_Titan_Token::instance();
$users  = get_users(array("login" => "testuser"));
$result = $plugin->grants->grant($users[0]->ID, "editor", time() + 120, 1);
var_dump($result["status"]);
'

# Check scheduled events
studio wp cron event list --format=table

# Force-run the expiry event (substitute the UUID)
studio wp eval 'do_action("t3admin_expire_grant", "GRANT-UUID-HERE");'

# Tail the JSONL log
tail -n 5 wp-content/uploads/t3admin-logs/access-grants.jsonl
```
