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

	const GRANTS_KEY          = 't3admin_grants';
	const MIGRATED_KEY        = 't3admin_grants_network_migrated';
	const MIGRATION_STATE_KEY = 't3admin_grants_network_migration_state';
	const EXPIRE_HOOK         = 't3admin_expire_grant';
	const SUPER_ADMIN_ROLE    = 'super_admin';
	const SCOPE_SITE          = 'site';
	const SCOPE_NETWORK       = 'network';
	const SCOPE_SUPER         = 'super_admin';

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
	 * The user's role in the database is never modified.  If grants are still
	 * active, their temporary role capabilities are merged on top of the
	 * user's real capabilities.  Expired grants are resolved inline as a safety
	 * net for missed scheduled events.
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

		$grants = $this->grants_for_user( $user->ID );
		if ( empty( $grants ) ) {
			return $allcaps;
		}

		global $wp_roles;

		foreach ( $grants as $grant ) {
			if ( $grant['expires_at'] <= time() ) {
				// Expire inline — static flag guards against re-entrance from any
				// cap check that may fire inside update_option().
				static $expiring = array();
				if ( empty( $expiring[ $grant['id'] ] ) ) {
					$expiring[ $grant['id'] ] = true;
					$this->expire_grant( $grant['id'] );
					unset( $expiring[ $grant['id'] ] );
				}
				continue;
			}

			if ( ! $this->grant_applies_to_blog( $grant, get_current_blog_id() ) ) {
				continue;
			}

			if ( self::SUPER_ADMIN_ROLE === $grant['temporary_role'] ) {
				// Super admin status on Multisite is handled by filter_super_admins();
				// inject administrator capabilities here to cover direct cap checks.
				$admin_role = $wp_roles->get_role( 'administrator' );
				if ( $admin_role ) {
					$allcaps = array_merge( $allcaps, $admin_role->capabilities );
				}
				continue;
			}

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
			if ( self::SCOPE_SUPER !== $this->grant_scope( $grant ) ) {
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
	 * Any existing active grant for the same user and scope target is
	 * superseded (revoked) before the new one is created.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id    ID of the user to elevate.
	 * @param string $new_role   Role slug to grant temporarily.
	 * @param int    $expires_at Unix timestamp when the grant expires.
	 * @param int    $granted_by ID of the admin creating the grant.
	 * @param int    $blog_id    Blog ID the grant applies to.
	 * @param string $scope      Scope key (site, network, super_admin).
	 * @return array|\WP_Error Grant record on success, WP_Error on failure.
	 */
	public function grant( int $user_id, string $new_role, int $expires_at, int $granted_by, int $blog_id = 0, string $scope = self::SCOPE_SITE ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'invalid_user', __( 'User not found.', 't3admin' ) );
		}

		$scope = $this->sanitize_scope( $scope, $new_role, $blog_id );
		if ( self::SCOPE_SUPER === $scope ) {
			$new_role = self::SUPER_ADMIN_ROLE;
			$blog_id  = 0;
		} elseif ( self::SCOPE_NETWORK === $scope ) {
			$blog_id = 0;
		} elseif ( is_multisite() ) {
			$blog_id = $blog_id ? $blog_id : get_current_blog_id();
		}

		$new_target = $this->grant_target_key(
			array(
				'scope'   => $scope,
				'blog_id' => $blog_id,
			)
		);

		foreach ( $this->active_grants() as $existing ) {
			if ( (int) $existing['user_id'] !== $user_id ) {
				continue;
			}
			if ( $new_target === $this->grant_target_key( $existing ) ) {
				$this->revoke( $existing['id'], $granted_by, 'superseded' );
			}
		}

		// Resolve the user's current role on the target blog.
		if ( is_multisite() && self::SCOPE_SITE === $scope && $blog_id && get_current_blog_id() !== $blog_id ) {
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
			'scope'          => $scope,
		);

		if ( is_multisite() && self::SCOPE_SITE === $scope && $blog_id ) {
			$entry['blog_id'] = $blog_id;
		}

		$grants        = $this->all_grants();
		$grants[ $id ] = $entry;
		$this->save_grants( $grants );
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
		$this->save_grants( $grants );
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
		$this->save_grants( $grants );
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
	 * Returns all grants (active, expired, and revoked) from canonical storage.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	public function all_grants(): array {
		if ( is_multisite() ) {
			$this->maybe_migrate_to_network_storage();
		}

		$raw        = $this->load_grants();
		$normalized = array();

		foreach ( $raw as $id => $grant ) {
			if ( ! is_array( $grant ) ) {
				continue;
			}
			if ( ! isset( $grant['id'] ) ) {
				$grant['id'] = is_string( $id ) ? $id : wp_generate_uuid4();
			}
			$normalized[ $grant['id'] ] = $this->normalize_grant( $grant );
		}

		return $normalized;
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
	 * Returns active grants for a specific user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<int, array>
	 */
	public function grants_for_user( int $user_id ): array {
		$matched = array();
		foreach ( $this->active_grants() as $grant ) {
			if ( (int) $grant['user_id'] === $user_id ) {
				$matched[] = $grant;
			}
		}
		return $matched;
	}

	/**
	 * Returns the active grant for a specific user, or null if none exists.
	 *
	 * This is used by the Users list column to show the strongest currently
	 * effective grant for the current blog context.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null Grant record, or null.
	 */
	public function user_active_grant( int $user_id ): ?array {
		$matched = array();
		foreach ( $this->grants_for_user( $user_id ) as $grant ) {
			if ( $grant['expires_at'] <= time() ) {
				continue;
			}
			if ( $this->grant_applies_to_blog( $grant, get_current_blog_id() ) ) {
				$matched[] = $grant;
			}
		}

		if ( empty( $matched ) ) {
			return null;
		}

		usort(
			$matched,
			function ( $a, $b ) {
				$prio_a = $this->scope_priority( $a );
				$prio_b = $this->scope_priority( $b );
				if ( $prio_a === $prio_b ) {
					return (int) $b['expires_at'] <=> (int) $a['expires_at'];
				}
				return $prio_b <=> $prio_a;
			}
		);

		return $matched[0];
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

	/**
	 * Returns the normalized scope value for a grant record.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grant Grant record.
	 * @return string
	 */
	public function grant_scope( array $grant ): string {
		if ( isset( $grant['scope'] ) && is_string( $grant['scope'] ) ) {
			$scope = sanitize_key( $grant['scope'] );
			if ( in_array( $scope, array( self::SCOPE_SITE, self::SCOPE_NETWORK, self::SCOPE_SUPER ), true ) ) {
				return $scope;
			}
		}

		if ( self::SUPER_ADMIN_ROLE === ( $grant['temporary_role'] ?? '' ) ) {
			return self::SCOPE_SUPER;
		}

		if ( ! empty( $grant['blog_id'] ) ) {
			return self::SCOPE_SITE;
		}

		return self::SCOPE_NETWORK;
	}

	/**
	 * Normalizes a grant record to include canonical scope details.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grant           Raw grant record.
	 * @param int   $default_site_id Optional site ID used during migration when a site-scoped
	 *                               grant has no valid blog_id. A value of 0 leaves the grant's
	 *                               existing blog_id unchanged and applies no fallback.
	 * @return array
	 */
	private function normalize_grant( array $grant, int $default_site_id = 0 ): array {
		$scope          = $this->grant_scope( $grant );
		$grant['scope'] = $scope;

		if ( self::SCOPE_SITE === $scope ) {
			$blog_id = isset( $grant['blog_id'] ) ? (int) $grant['blog_id'] : 0;
			if ( ! $blog_id && $default_site_id ) {
				$blog_id = $default_site_id;
			}
			if ( $blog_id ) {
				$grant['blog_id'] = $blog_id;
			}
		} else {
			unset( $grant['blog_id'] );
		}

		if ( self::SCOPE_SUPER === $scope ) {
			$grant['temporary_role'] = self::SUPER_ADMIN_ROLE;
		}

		return $grant;
	}

	/**
	 * Returns whether a grant applies to a blog.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grant   Grant record.
	 * @param int   $blog_id Blog ID.
	 * @return bool
	 */
	private function grant_applies_to_blog( array $grant, int $blog_id ): bool {
		$scope = $this->grant_scope( $grant );
		if ( self::SCOPE_SUPER === $scope || self::SCOPE_NETWORK === $scope ) {
			return true;
		}

		$grant_blog = isset( $grant['blog_id'] ) ? (int) $grant['blog_id'] : 0;
		if ( ! $grant_blog ) {
			return false;
		}

		return $grant_blog === $blog_id;
	}

	/**
	 * Returns priority for choosing a display grant.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grant Grant record.
	 * @return int
	 */
	private function scope_priority( array $grant ): int {
		$scope = $this->grant_scope( $grant );
		if ( self::SCOPE_SUPER === $scope ) {
			return 3;
		}
		if ( self::SCOPE_NETWORK === $scope ) {
			return 2;
		}
		return 1;
	}

	/**
	 * Sanitizes a requested scope based on role and context.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope    Requested scope.
	 * @param string $new_role Requested role.
	 * @param int    $blog_id  Requested blog ID.
	 * @return string
	 */
	private function sanitize_scope( string $scope, string $new_role, int $blog_id ): string {
		$scope = sanitize_key( $scope );

		if ( self::SUPER_ADMIN_ROLE === $new_role ) {
			return self::SCOPE_SUPER;
		}

		if ( ! is_multisite() ) {
			return self::SCOPE_SITE;
		}

		if ( self::SCOPE_NETWORK === $scope ) {
			return self::SCOPE_NETWORK;
		}

		if ( self::SCOPE_SITE === $scope ) {
			return self::SCOPE_SITE;
		}

		if ( $blog_id ) {
			return self::SCOPE_SITE;
		}

		return self::SCOPE_NETWORK;
	}

	/**
	 * Returns a target key used for superseding existing grants.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grant Grant record.
	 * @return string
	 */
	private function grant_target_key( array $grant ): string {
		$scope = $this->grant_scope( $grant );
		if ( self::SCOPE_SUPER === $scope ) {
			return self::SCOPE_SUPER;
		}
		if ( self::SCOPE_NETWORK === $scope ) {
			return self::SCOPE_NETWORK;
		}

		$blog_id = isset( $grant['blog_id'] ) ? (int) $grant['blog_id'] : 0;
		return self::SCOPE_SITE . ':' . (string) $blog_id;
	}

	/**
	 * Loads grant records from canonical storage.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	private function load_grants(): array {
		if ( is_multisite() ) {
			return (array) get_site_option( self::GRANTS_KEY, array() );
		}
		return (array) get_option( self::GRANTS_KEY, array() );
	}

	/**
	 * Persists grant records to canonical storage.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array> $grants Grant map.
	 */
	private function save_grants( array $grants ): void {
		if ( is_multisite() ) {
			update_site_option( self::GRANTS_KEY, $grants );
			return;
		}
		update_option( self::GRANTS_KEY, $grants, false );
	}

	/**
	 * Migrates legacy per-site grant stores into network canonical storage.
	 *
	 * @since 1.0.0
	 */
	private function maybe_migrate_to_network_storage(): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( get_site_option( self::MIGRATED_KEY ) ) {
			return;
		}

		$network_grants = (array) get_site_option( self::GRANTS_KEY, array() );
		if ( ! empty( $network_grants ) ) {
			delete_site_option( self::MIGRATION_STATE_KEY );
			update_site_option( self::MIGRATED_KEY, 1 );
			return;
		}

		$state  = (array) get_site_option( self::MIGRATION_STATE_KEY, array() );
		$merged = isset( $state['merged'] ) && is_array( $state['merged'] ) ? $state['merged'] : array();
		$offset = isset( $state['offset'] ) ? (int) $state['offset'] : 0;

		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		$total_sites = count( $sites );
		if ( $offset >= $total_sites ) {
			update_site_option( self::GRANTS_KEY, $merged );
			delete_site_option( self::MIGRATION_STATE_KEY );
			update_site_option( self::MIGRATED_KEY, 1 );
			return;
		}

		$time_budget = (float) apply_filters( 't3admin_migration_time_budget', 2.0 );
		if ( $time_budget <= 0.0 ) {
			$time_budget = 2.0;
		}
		$started_at = microtime( true );

		for ( $i = $offset; $i < $total_sites; $i++ ) {
			$site_id = $sites[ $i ];
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
			$site_grants = (array) get_option( self::GRANTS_KEY, array() );
			restore_current_blog();

			foreach ( $site_grants as $id => $grant ) {
				if ( ! is_array( $grant ) ) {
					continue;
				}
				if ( ! isset( $grant['id'] ) ) {
					$grant['id'] = is_string( $id ) ? $id : wp_generate_uuid4();
				}

				$grant = $this->normalize_grant( $grant, $site_id );
				if ( isset( $merged[ $grant['id'] ] ) ) {
					$original_id        = $grant['id'];
					$grant['legacy_id'] = $original_id;
					$grant['id']        = wp_generate_uuid4();
				}

				$merged[ $grant['id'] ] = $grant;
			}

			$offset = $i + 1;
			if ( ( microtime( true ) - $started_at ) >= $time_budget && $offset < $total_sites ) {
				update_site_option(
					self::MIGRATION_STATE_KEY,
					array(
						'merged' => $merged,
						'offset' => $offset,
					)
				);
				return;
			}
		}

		update_site_option( self::GRANTS_KEY, $merged );
		delete_site_option( self::MIGRATION_STATE_KEY );
		update_site_option( self::MIGRATED_KEY, 1 );
	}
}
