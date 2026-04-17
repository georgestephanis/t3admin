<?php
/**
 * WP-CLI commands for temporary role grants.
 *
 * @package t3admin
 * @since   1.0.0
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WP-CLI commands for managing temporary grants.
 *
 * @since 1.0.0
 */
class CLI extends \WP_CLI_Command {

	/**
	 * Prevents duplicate command registration.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Registers the root WP-CLI command.
	 *
	 * @since 1.0.0
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		\WP_CLI::add_command( 't3admin', __CLASS__ );
		self::$registered = true;
	}

	/**
	 * Grants a temporary role set to a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--role=<role>]
	 * : Single temporary role slug.
	 *
	 * [--roles=<roles>]
	 * : Comma-separated temporary role slugs to apply exactly.
	 *
	 * [--remove-roles=<roles>]
	 * : Comma-separated role slugs to remove temporarily from the user's current site role set.
	 *
	 * [--remove-all-roles]
	 * : Temporarily remove all roles from the user.
	 *
	 * [--duration=<interval>]
	 * : Relative duration, for example "2 hours" or "3 days".
	 *
	 * [--expires=<datetime>]
	 * : Specific expiry datetime in the site's timezone.
	 *
	 * [--scope=<scope>]
	 * : site, network, or super_admin.
	 *
	 * [--blog-id=<id>]
	 * : Blog ID for site-scoped grants.
	 *
	 * [--granted-by=<user>]
	 * : Optional actor user ID, login, or email for audit attribution.
	 *
	 * ## EXAMPLES
	 *
	 *     wp t3admin grant alice --role=subscriber --duration="2 days"
	 *     wp t3admin grant 55 --roles=subscriber,author --expires="2026-04-20 17:00"
	 *     wp t3admin grant bob --remove-roles=editor --duration="1 day"
	 *     wp t3admin grant bob --remove-all-roles --duration="12 hours"
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function grant( array $args, array $assoc_args ): void {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			\WP_CLI::error( __( 'User not found.', 't3admin' ) );
		}

		$scope      = sanitize_key( $assoc_args['scope'] ?? Grants::SCOPE_SITE );
		$blog_id    = isset( $assoc_args['blog-id'] ) ? absint( $assoc_args['blog-id'] ) : 0;
		$granted_by = isset( $assoc_args['granted-by'] ) ? $this->resolve_user_id( $assoc_args['granted-by'] ) : 0;
		$expires_at = $this->resolve_expiry_timestamp( $assoc_args );
		$roles      = $this->resolve_temporary_roles( $user, $scope, $blog_id, $assoc_args );

		if ( is_wp_error( $roles ) ) {
			\WP_CLI::error( $roles->get_error_message() );
		}

		$result = grants()->grant_roles( $user->ID, $roles, $expires_at, $granted_by, $blog_id, $scope );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: grant ID, 2: temporary roles, 3: expiry timestamp */
				__( 'Grant %1$s active with temporary roles: %2$s (expires %3$s).', 't3admin' ),
				$result['id'],
				grants()->role_set_label( grants()->grant_temporary_roles( $result ) ),
				wp_date( 'Y-m-d H:i', $result['expires_at'] )
			)
		);
	}

	/**
	 * Revokes an active grant by ID.
	 *
	 * ## OPTIONS
	 *
	 * <grant-id>
	 * : Grant UUID.
	 *
	 * [--revoked-by=<user>]
	 * : Optional actor user ID, login, or email for audit attribution.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$grant_id    = sanitize_text_field( $args[0] ?? '' );
		$revoked_by  = isset( $assoc_args['revoked-by'] ) ? $this->resolve_user_id( $assoc_args['revoked-by'] ) : 0;
		$was_revoked = grants()->revoke( $grant_id, $revoked_by );

		if ( ! $was_revoked ) {
			\WP_CLI::error( __( 'Grant not found or already resolved.', 't3admin' ) );
		}

		\WP_CLI::success( __( 'Grant revoked.', 't3admin' ) );
	}

	/**
	 * Resolves a WP user from ID, login, or email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $identifier User identifier.
	 * @return \WP_User|null
	 */
	private function resolve_user( string $identifier ): ?\WP_User {
		if ( '' === $identifier ) {
			return null;
		}

		if ( ctype_digit( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $identifier );
		if ( $user ) {
			return $user;
		}

		$user = get_user_by( 'email', $identifier );
		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Resolves a user identifier to a user ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $identifier User identifier.
	 * @return int
	 */
	private function resolve_user_id( string $identifier ): int {
		$user = $this->resolve_user( $identifier );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Resolves the requested expiry time.
	 *
	 * @since 1.0.0
	 *
	 * @param array $assoc_args CLI options.
	 * @return int
	 */
	private function resolve_expiry_timestamp( array $assoc_args ): int {
		$has_duration = isset( $assoc_args['duration'] );
		$has_expires  = isset( $assoc_args['expires'] );

		if ( $has_duration === $has_expires ) {
			\WP_CLI::error( __( 'Specify exactly one of --duration or --expires.', 't3admin' ) );
		}

		if ( $has_expires ) {
			try {
				$dt = new \DateTimeImmutable( (string) $assoc_args['expires'], wp_timezone() );
			} catch ( \Exception $exception ) {
				\WP_CLI::error( __( 'Invalid --expires value.', 't3admin' ) );
			}

			$expires_at = $dt->getTimestamp();
		} else {
			$modifier = trim( (string) $assoc_args['duration'] );
			if ( '' === $modifier ) {
				\WP_CLI::error( __( 'Duration must not be empty.', 't3admin' ) );
			}

			if ( 0 !== strpos( $modifier, '+' ) && 0 !== strpos( $modifier, '-' ) ) {
				$modifier = '+' . $modifier;
			}

			$dt = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modifier );
			if ( false === $dt ) {
				\WP_CLI::error( __( 'Invalid --duration value.', 't3admin' ) );
			}

			$expires_at = $dt->getTimestamp();
		}

		if ( time() >= $expires_at ) {
			\WP_CLI::error( __( 'Expiry time must be in the future.', 't3admin' ) );
		}

		return $expires_at;
	}

	/**
	 * Resolves the temporary role set from CLI options.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_User $user       Target user.
	 * @param string   $scope      Grant scope.
	 * @param int      $blog_id    Site scope target.
	 * @param array    $assoc_args CLI options.
	 * @return array|\WP_Error
	 */
	private function resolve_temporary_roles( \WP_User $user, string $scope, int $blog_id, array $assoc_args ) {
		if ( Grants::SCOPE_SUPER === $scope ) {
			return array();
		}

		$modes = array_filter(
			array(
				isset( $assoc_args['role'] ),
				isset( $assoc_args['roles'] ),
				isset( $assoc_args['remove-roles'] ),
				isset( $assoc_args['remove-all-roles'] ),
			)
		);

		if ( 1 !== count( $modes ) ) {
			return new \WP_Error( 'invalid_roles', __( 'Specify exactly one role mode: --role, --roles, --remove-roles, or --remove-all-roles.', 't3admin' ) );
		}

		if ( isset( $assoc_args['role'] ) ) {
			return array( sanitize_key( (string) $assoc_args['role'] ) );
		}

		if ( isset( $assoc_args['roles'] ) ) {
			return $this->csv_roles( (string) $assoc_args['roles'] );
		}

		if ( isset( $assoc_args['remove-all-roles'] ) ) {
			return array();
		}

		if ( Grants::SCOPE_NETWORK === $scope ) {
			return new \WP_Error( 'invalid_roles', __( 'Use --roles for network-wide grants; --remove-roles is only supported for site scope.', 't3admin' ) );
		}

		$current_roles = grants()->current_roles_for_target( $user->ID, $blog_id, $scope );
		$remove_roles  = $this->csv_roles( (string) $assoc_args['remove-roles'] );
		return array_values( array_diff( $current_roles, $remove_roles ) );
	}

	/**
	 * Converts a comma-separated role list into slugs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $roles Comma-separated roles.
	 * @return string[]
	 */
	private function csv_roles( string $roles ): array {
		$items = array_map( 'trim', explode( ',', $roles ) );
		$items = array_filter( $items, 'strlen' );
		return array_values( array_unique( array_map( 'sanitize_key', $items ) ) );
	}
}
