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

Temporary Titan Token lets administrators grant temporary role capabilities for a fixed window of time. When the window closes, the grant is automatically resolved.

= Key features =

* **Flexible expiry** - set a specific date and time (site timezone) or a duration in minutes, hours, or days.
* **Two-layer expiry enforcement** - each grant schedules a precise single cron event, and a `user_has_cap` safety net expires overdue grants inline.
* **Instant revocation** - cancel any active grant immediately from the admin UI.
* **Scoped superseding** - a new grant supersedes prior active grants for the same target scope.
* **JSONL audit log** - all grants, revocations, and expiries are logged to a line-delimited JSON file.
* **In-admin log viewer** - browse audit logs in the **Access Logs** tab under **Users -> Temp Roles**.
* **Multisite scopes** - site admin grants current-site access; network admin grants single-site, whole-network, or temporary super-admin access.
* **Adaptive user picker** - small sites get a direct dropdown; larger installs and Network Admin use live user search.

= How it works =

When a grant is created, the plugin stores grant metadata and schedules `wp_schedule_single_event()` for the expiry timestamp. While active, temporary capabilities are overlaid via `user_has_cap`. When expired or revoked, capability overlay stops immediately.

= Privacy =

The plugin stores:

* User IDs for elevated users and granting/revoking admins.
* Role slugs (original and temporary).
* Grant lifecycle timestamps.

Storage locations:

* Grants in WordPress options (site option on single-site, network option on Multisite).
* Audit log at `wp-content/uploads/t3admin-logs/access-grants.jsonl`.

No data is transmitted to external services.

== Installation ==

1. Upload the `t3admin` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins** in wp-admin.
3. Go to **Users -> Temp Roles** to manage grants.

On smaller sites, the grant form shows the full user dropdown directly. On larger sites and in Network Admin, it switches to a live search so the form stays responsive.

== Frequently Asked Questions ==

= Who can use this plugin? =

Any user with the `promote_users` capability. This is typically Administrators on single-site and Super Admins on Multisite.

= What happens if WP-Cron is delayed? =

The `user_has_cap` filter checks grants during capability evaluation and expires overdue grants inline, so elevated capabilities do not outlive their window.

= Can a user have more than one active temporary grant? =

Yes, across different scope targets. A new grant supersedes only grants that target the same scope key.

= What happens on plugin deactivation? =

Scheduled expiry events are unscheduled. Temporary capability overlay also stops because it is applied by this plugin's filters.

= Where is the audit log stored and protected? =

The log file is `wp-content/uploads/t3admin-logs/access-grants.jsonl`.

The plugin writes:

* `.htaccess` deny rules for Apache
* `web.config` deny rules for IIS
* `index.php` stub

For Nginx, add an explicit deny rule for `/wp-content/uploads/t3admin-logs/`.

= Is this plugin compatible with Multisite? =

Yes. Site-admin context supports current-site grants. Network-admin context supports single-site, whole-network, and temporary super-admin grants.

= Where can I report issues? =

Please open an issue on the GitHub repository:
https://github.com/georgestephanis/t3admin

== Screenshots ==

1. Grant form with scope and expiry controls.
2. Active grants table with remaining time and revoke action.
3. Access Logs tab with event badges and pagination.

== Changelog ==

= 1.0.0 =
* Initial release.
* Temporary role capability grants with datetime or duration expiry.
* Per-grant single cron events and inline expiry safety net.
* JSONL audit log and in-admin log viewer.
* Multisite-aware scope handling.

== Upgrade Notice ==

= 1.0.0 =
Initial release - no upgrade steps required.
