=== Temporary Titan Token ===
Contributors:      georgestephanis
Tags:              users, roles, permissions, temporary, admin
Requires at least: 6.0
Tested up to:      6.9
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Hold the title of titan, if only for a tick.

== Description ==

Temporary Titan Token lets site administrators promote any user to a higher WordPress role for a fixed window of time.  When the window closes the user's original role is automatically restored — no manual cleanup, no elevated permissions left behind.

= Key features =

* **Flexible expiry** — set a specific date and time (in the site's configured timezone) or a simple duration: N minutes, hours, or days from now.
* **Two-layer expiry enforcement** — a per-grant scheduled event fires at the exact expiry moment.  A `user_has_cap` filter acts as a backstop so that even if WP-Cron is delayed, elevated permissions cannot outlive their window.
* **Instant revocation** — cancel any active grant early with one click and the original role is restored immediately.
* **Superseding grants** — granting a new temporary role to a user who already has one automatically revokes the previous grant first.
* **JSONL audit log** — every grant, revocation, and automatic expiry is appended to a line-delimited JSON file, protected from direct web access.
* **In-admin log viewer** — browse the audit log inside wp-admin without needing file-system access.
* **Multisite-ready** — uses the `promote_users` capability, held by Administrators on single sites and Super Admins on Multisite networks.
* **Fully internationalised** — all strings use the `t3admin` text domain and are ready for translation.

= How it works =

When a grant is created the user's current role is stored internally and their role is immediately changed to the temporary one.  `wp_schedule_single_event()` is called to fire the expiry at the exact timestamp.  Additionally, a `user_has_cap` filter checks every capability request — if a grant is overdue it is revoked inline, ensuring expiry even on low-traffic sites with an unreliable cron.

= Privacy =

The plugin stores the following data in the WordPress options table and in a log file on the server:

* WordPress user IDs of elevated users and the admins who granted access.
* Role slugs (before and after elevation).
* Grant timestamps and expiry timestamps.

No data is transmitted to external services.  The log file is stored inside `wp-content/uploads/` and is blocked from direct HTTP access via `.htaccess`.

== Installation ==

1. Upload the `t3admin` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Users → Temp Roles** to begin granting temporary roles.

== Frequently Asked Questions ==

= Who can use this plugin? =

Any user with the `promote_users` capability — Administrators on single-site installs, and Super Admins on Multisite networks.

= What happens if WP-Cron doesn't run? =

A `user_has_cap` filter checks every capability request made for the elevated user.  If the grant has expired the role is reverted inline, before WordPress processes the capability.  Expiry cannot be silently skipped.

= Can a user have more than one active temporary grant at a time? =

No.  Each user can hold at most one active grant.  Granting a new temporary role to a user who already has one automatically revokes the previous grant (logged as `superseded`) before creating the new one.

= What happens to the user's role if I deactivate the plugin? =

On deactivation the plugin only removes its pending scheduled events.  It does **not** automatically revert any active grants.  Elevated roles that were set by the plugin will remain until the plugin is re-activated and the grants expire, or until an administrator manually changes those users' roles.  If you are deactivating permanently, revoke all active grants first via **Users → Temp Roles**.

= Where is the audit log stored? =

The log file is at `wp-content/uploads/t3admin-logs/access-grants.jsonl`.  The directory is protected from direct HTTP access by an `.htaccess` file (Apache) and an `index.php` stub.  You can view log entries inside wp-admin at **Users → Temp Role Logs**.

= Can I grant a role higher than Administrator? =

Only roles registered with WordPress are available.  The dropdown is populated from `WP_Roles`, so custom roles added by other plugins will appear.  There is no built-in restriction preventing an admin from granting another user the Administrator role temporarily — exercise the same discretion you would when using WordPress's own role management.

= Is this plugin compatible with Multisite? =

Yes.  The plugin operates per-site.  Super Admins see the menu and can elevate users on any site they administer.  Network-level role management is outside the scope of this plugin.

= Where can I report a bug or request a feature? =

Please open an issue on the [GitHub repository](https://github.com/georgestephanis/t3admin).

== Screenshots ==

1. The **Grant Temporary Role** form with duration-mode selected.
2. The **Active Grants** table showing remaining time and a Revoke button.
3. The **Access Logs** viewer with colour-coded event badges.

== Changelog ==

= 1.0.0 =
* Initial release.
* Grant temporary roles with specific datetime or duration expiry.
* Per-grant scheduled single events for precise expiry.
* Real-time expiry enforcement via `user_has_cap` filter.
* JSONL audit log with in-admin viewer.
* Full WordPress Coding Standards compliance.
* All strings internationalised with the `t3admin` text domain.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
