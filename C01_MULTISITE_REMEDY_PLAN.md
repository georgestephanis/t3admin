# C-01 Multisite Remedy Plan

Date: 2026-04-17
Concern: C-01 (Multisite storage model can break cross-site grants)

## Goal

Establish a single canonical grant store on multisite while preserving the UX split:
- Site admin panel can grant temporary permissions for the current site only.
- Network admin panel can grant site-scoped, network-wide, and temporary super-admin permissions.

## C-01a Data Model and Storage

1. Canonical storage
- On multisite, store grants in network options (`get_site_option` / `update_site_option`) under `t3admin_grants`.
- On single-site, continue using site options (`get_option` / `update_option`).

2. Grant scope representation
- Add `scope` field to new/updated grant records:
  - `site`: grant applies to one site (`blog_id` required)
  - `network`: grant applies to all sites (non-super-admin role overlay)
  - `super_admin`: temporary super-admin on multisite

3. Backward compatibility
- Treat legacy records as:
  - `super_admin` if role is `super_admin`
  - `site` if `blog_id` exists and is non-zero
  - `network` otherwise

## C-01b Authorization and Grant Rules

1. Site admin context (`wp-admin` on a site)
- Allow only `site` grants.
- Force `blog_id` to current blog ID.
- Forbid selecting `super_admin` and forbid creating `network` scope.

2. Network admin context (`network/wp-admin`)
- Super Admin can create:
  - `site` grants for any existing site
  - `network` grants
  - `super_admin` grants

3. Supersede behavior
- Supersede by scope target, not by user globally:
  - `site`: supersede existing active grants for same `user_id + blog_id`
  - `network`: supersede existing active grants for same `user_id + scope=network`
  - `super_admin`: supersede existing active grants for same `user_id + scope=super_admin`

## C-01c Capability Enforcement

1. Effective grants for current request
- Filter active grants for the user to the current context:
  - Always include active `super_admin` scope grants.
  - Include active `network` scope grants.
  - Include active `site` scope grants only when `blog_id === get_current_blog_id()`.

2. Expiry safety net
- During `user_has_cap`, mark overdue grants as expired inline (current behavior) for all matched grants.

3. Super-admin integration
- `site_option_site_admins` filter should include users with active `super_admin` scope grants only.

## C-01d Admin UX and Routing

1. Menu exposure
- Keep plugin page in site admin.
- Also register in network admin menu on multisite.

2. Form controls
- Site admin page:
  - No scope selector.
  - No site selector.
  - No super-admin role option.
- Network admin page:
  - Add `Grant Scope` selector: Site, Network, Super Admin.
  - Show site selector only when `Grant Scope = Site`.
  - Show role selector only for Site/Network scopes.
  - Super Admin scope maps to synthetic role `super_admin`.

3. Redirect correctness
- Use context-aware URLs (`admin_url` vs `network_admin_url`) for page and post handlers.

## C-01e Migration

No migration is required for the current pre-release phase.

- The plugin now assumes a single canonical format for grants.
- Multisite uses network option storage directly.
- Legacy compatibility and staged migration code are intentionally omitted.

## C-01f Verification and Tests

1. Functional checks
- Site admin can only create current-site grants.
- Network admin can create site/network/super-admin grants.
- A network grant applies across sites.
- A site grant applies only on target site.
- Super-admin grant affects `is_super_admin()` checks until expiry/revocation.

2. Regression checks
- Existing single-site installs remain unchanged.
- Existing multisite grants continue to function after migration.
- Revocation/expiry and JSONL logs still work.

3. Tooling
- Run PHPCS and fix any new violations.
- Run WP-CLI smoke flows for site and network contexts.

## Proposed Patch Sequence

1. Storage and normalization layer in `Grants`.
2. Scope-aware grant creation and supersede logic.
3. Scope-aware cap enforcement and super-admin filter.
4. Context-aware admin routing and UI controls.
5. Migration routine and marker.
6. Lint/smoke validation.
