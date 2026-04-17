<?php
/**
 * Grant storage, capability overlay, and expiry logic.
 *
 * @package t3admin
 * @since   1.0.0
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manages temporary role grants and enforces them via capability filters.
 *
 * The user's role in the database is never modified.  Elevation is applied
 * purely through the user_has_cap filter, so deactivating or deleting the
 * plugin automatically reverts all users.
 *
 * @since 1.0.0
 */
class Grants {

	const GRANTS_KEY       = 't3admin_grants';
	const EXPIRE_HOOK      = 't3admin_expire_grant';
	const SUPER_ADMIN_ROLE = 'super_admin';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function setup_hooks(): void {
		add_action( self::EXPIRE_HOOK, array( $this, 'expire_grant' ) );
		add_filter( 'user_has_cap', array( $this, 'filter_user_caps' ), 10, 4 );
		if ( is_multisite() ) {
			add_filter( 'site_option_site_admins', array( $this, 'filter_super_admins' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Capability filters
	// -------------------------------------------------------------------------

	/**
	 * Overlays temporary role capabilities on every capability check.
	 *
	 * The user's role in the database is never modified.  If the grant is
	 * still active the temporary role's capabilities are merged on top of the
	 * user's real capabilities.  If the grant has expired it is marked as such
	 * inline (safety net for missed scheduled events) and the real caps are
	 * returned unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param bool[]   $allcaps Array of the user's capabilities.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments: [0] requested capability, [1] user ID.
	 * @param \WP_User $user    The user object.
	 * @return bool[]
	 */
	public function filter_user_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $allcaps;
		}
		$grant = $this->user_active_grant( $user->ID );
		if ( ! $grant ) {
			return $allcaps;
		}
		if ( $grant['expires_at'] <= time() ) {
			// Expire inline — static flag guards against re-entrance from any
			// cap check that may fire inside update_option().
			static $expiring = array();
			if ( empty( $expiring[ $grant['id'] ] ) ) {
				$expiring[ $grant['id'] ] = true;
				$this->expire_grant( $grant['id'] );
				unset( $expiring[ $grant['id'] ] );
			}
			return $allcaps;
		}
		// On Multisite, non-super-admin grants are scoped to a single blog.
		if ( is_multisite() && self::SUPER_ADMIN_ROLE !== $grant['temporary_role'] ) {
			$grant_blog = isset( $grant['blog_id'] ) ? (int) $grant['blog_id'] : 0;
			if ( $grant_blog && $grant_blog !== get_current_blog_id() ) {
				return $allcaps;
			}
		}
		global $wp_roles;
		if ( self::SUPER_ADMIN_ROLE === $grant['temporary_role'] ) {
			// Super admin status on Multisite is handled by filter_super_admins();
			// inject administrator capabilities here to cover direct cap checks.
			$admin_role = $wp_roles->get_role( 'administrator' );
			if ( $admin_role ) {
				$allcaps = array_merge( $allcaps, $admin_role->capabilities );
			}
		} else {
			$role = $wp_roles->get_role( $grant['temporary_role'] );
			if ( $role ) {
				$allcaps = array_merge( $allcaps, $role->capabilities );
			}
		}
		return $allcaps;
	}

	/**
	 * Injects temporarily-elevated users into the Multisite super-admin list.
	 *
	 * WordPress's is_super_admin() reads the 'site_admins' network option
	 * directly rather than going through user_has_cap, so we filter that
	 * option value to add any user who holds an active super-admin grant.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $super_admins Value of the site_admins network option.
	 * @return array
	 */
	public function filter_super_admins( $super_admins ) {
		if ( ! is_array( $super_admins ) ) {
			$super_admins = array();
		}
		foreach ( $this->active_grants() as $grant ) {
			if ( self::SUPER_ADMIN_ROLE !== $grant['temporary_role'] ) {
				continue;
			}
			if ( $grant['expires_at'] <= time() ) {
				continue;
			}
			$user = get_user_by( 'id', $grant['user_id'] );
			if ( $user && ! in_array( $user->user_login, $super_admins, true ) ) {
				$super_admins[] = $user->user_login;
			}
		}
		return $super_admins;
	}

	// -------------------------------------------------------------------------
	// Grant lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Creates a temporary role grant for a user.
	 *
	 * Any existing active grant for the same user is superseded (revoked)
	 * before the new one is created.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id    ID of the user to elevate.
	 * @param string $new_role   Role slug to grant temporarily.
	 * @param int    $expires_at Unix timestamp when the grant expires.
	 * @param int    $granted_by ID of the admin creating the grant.
	 * @param int    $blog_id    Blog ID the grant applies to (0 = current; ignored for super_admin).
	 * @return array|\WP_Error Grant record on success, WP_Error on failure.
	 */
	public function grant( int $user_id, string $new_role, int $expires_at, int $granted_by, int $blog_id = 0 ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'invalid_user', __( 'User not found.', 't3admin' ) );
		}
		$existing = $this->user_active_grant( $user_id );
		if ( $existing ) {
			$this->revoke( $existing['id'], $granted_by, 'superseded' );
		}
		// Resolve the user's current role on the target blog.
		if ( is_multisite() && $blog_id && $blog_id !== get_current_blog_id() ) {
			$blog_user = new \WP_User( $user_id, '', $blog_id );
			$original  = ! empty( $blog_user->roles ) ? $blog_user->roles[0] : 'subscriber';
		} else {
			$original = ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
		}
		$id    = wp_generate_uuid4();
		$entry = array(
			'id'             => $id,
			'user_id'        => $user_id,
			'original_role'  => $original,
			'temporary_role' => $new_role,
			'granted_by'     => $granted_by,
			'granted_at'     => time(),
			'expires_at'     => $expires_at,
			'status'         => 'active',
		);
		if ( is_multisite() && $blog_id ) {
			$entry['blog_id'] = $blog_id;
		}
		$grants        = $this->all_grants();
		$grants[ $id ] = $entry;
		update_option( self::GRANTS_KEY, $grants, false );
		wp_schedule_single_event( $expires_at, self::EXPIRE_HOOK, array( $id ) );
		$this->logger->log( 'granted', $entry );
		return $entry;
	}

	/**
	 * Revokes an active grant.
	 *
	 * The user's database role is not modified.  Removing the active grant
	 * record is sufficient — filter_user_caps() stops overlaying capabilities
	 * on the next request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $grant_id   UUID of the grant to revoke.
	 * @param int    $revoked_by ID of the admin performing the revocation.
	 * @param string $reason     Machine-readable reason ('manual', 'superseded').
	 * @return bool True on success, false if the grant was not found or already resolved.
	 */
	public function revoke( string $grant_id, int $revoked_by, string $reason = 'manual' ): bool {
		$grants = $this->all_grants();
		if ( ! isset( $grants[ $grant_id ] ) || 'active' !== $grants[ $grant_id ]['status'] ) {
			return false;
		}
		$grants[ $grant_id ]['status']        = 'revoked';
		$grants[ $grant_id ]['resolved_at']   = time();
		$grants[ $grant_id ]['revoked_by']    = $revoked_by;
		$grants[ $grant_id ]['revoke_reason'] = $reason;
		update_option( self::GRANTS_KEY, $grants, false );
		$this->logger->log( 'revoked', $grants[ $grant_id ] );
		$ts = wp_next_scheduled( self::EXPIRE_HOOK, array( $grant_id ) );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EXPIRE_HOOK, array( $grant_id ) );
		}
		return true;
	}

	/**
	 * Marks a grant as expired; called by its dedicated scheduled event.
	 *
	 * Safe to call multiple times — returns early if the grant is already
	 * resolved.
	 *
	 * @since 1.0.0
	 *
	 * @param string $grant_id UUID of the grant to expire.
	 */
	public function expire_grant( string $grant_id ): void {
		$grants = $this->all_grants();
		if ( ! isset( $grants[ $grant_id ] ) || 'active' !== $grants[ $grant_id ]['status'] ) {
			return;
		}
		$grants[ $grant_id ]['status']      = 'expired';
		$grants[ $grant_id ]['resolved_at'] = time();
		update_option( self::GRANTS_KEY, $grants, false );
		$this->logger->log( 'expired', $grants[ $grant_id ] );
		$ts = wp_next_scheduled( self::EXPIRE_HOOK, array( $grant_id ) );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EXPIRE_HOOK, array( $grant_id ) );
		}
	}

	// -------------------------------------------------------------------------
	// Query helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns all grants (active, expired, and revoked) from the option store.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	public function all_grants(): array {
		return (array) get_option( self::GRANTS_KEY, array() );
	}

	/**
	 * Returns only the currently active grants.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	public function active_grants(): array {
		return array_filter(
			$this->all_grants(),
			function ( $g ) {
				return isset( $g['status'] ) && 'active' === $g['status'];
			}
		);
	}

	/**
	 * Returns the active grant for a specific user, or null if none exists.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null Grant record, or null.
	 */
	public function user_active_grant( int $user_id ): ?array {
		foreach ( $this->active_grants() as $g ) {
			if ( (int) $g['user_id'] === $user_id ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * Returns a human-readable label for a role slug.
	 *
	 * Handles the synthetic 'super_admin' sentinel in addition to standard
	 * WordPress role slugs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Role slug or self::SUPER_ADMIN_ROLE.
	 * @return string Translated display name.
	 */
	public function role_label( string $slug ): string {
		if ( self::SUPER_ADMIN_ROLE === $slug ) {
			return __( 'Super Admin', 't3admin' );
		}
		global $wp_roles;
		$name = isset( $wp_roles->roles[ $slug ]['name'] ) ? $wp_roles->roles[ $slug ]['name'] : $slug;
		return translate_user_role( $name );
	}
}
