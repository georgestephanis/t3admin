<?php
/**
 * Plugin Name:  Temporary Titan Token
 * Description:  Hold the title of titan, if only for a tick
 * Version:      1.0.0
 * Text Domain:  t3admin
 * Requires PHP: 7.4
 *
 * @package t3admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Temporary Titan Token — overlays a temporary elevated role on a user
 * without touching their role in the database.
 *
 * Elevation is applied purely via the user_has_cap filter, so the user's
 * stored role is never modified.  Deactivating or deleting the plugin
 * automatically removes the overlay and the user's real role takes effect
 * immediately.  All grants are recorded in a JSONL audit log and a per-grant
 * wp_schedule_single_event() marks the grant expired at the right moment;
 * the filter also expires overdue grants inline as a safety net.
 *
 * @since 1.0.0
 */
class Temporary_Titan_Token {

	const VERSION          = '1.0.0';
	const GRANTS_KEY       = 't3admin_grants';
	const EXPIRE_HOOK      = 't3admin_expire_grant';
	const CAP              = 'promote_users';
	const LOG_DIR          = 't3admin-logs';
	const LOG_FILE         = 'access-grants.jsonl';
	const SUPER_ADMIN_ROLE = 'super_admin';

	/**
	 * Singleton instance.
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * Returns the singleton instance, creating it on first call.
	 *
	 * @since 1.0.0
	 * @return self
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers activation/deactivation hooks and the init action.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'init', array( $this, 'setup' ) );
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Runs on plugin activation: creates the log directory.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		$this->ensure_log_dir();
	}

	/**
	 * Runs on plugin deactivation: clears all pending per-grant cron events.
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		foreach ( $this->all_grants() as $grant ) {
			if ( 'active' === $grant['status'] ) {
				$ts = wp_next_scheduled( self::EXPIRE_HOOK, array( $grant['id'] ) );
				if ( $ts ) {
					wp_unschedule_event( $ts, self::EXPIRE_HOOK, array( $grant['id'] ) );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Setup
	// -------------------------------------------------------------------------

	/**
	 * Registers all WordPress hooks used by the plugin.
	 *
	 * @since 1.0.0
	 */
	public function setup() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( self::EXPIRE_HOOK, array( $this, 'expire_grant' ) );
		add_action( 'admin_post_t3admin_grant', array( $this, 'handle_grant' ) );
		add_action( 'admin_post_t3admin_revoke', array( $this, 'handle_revoke' ) );

		// Overlay temporary capabilities and enforce expiry on every cap check.
		add_filter( 'user_has_cap', array( $this, 'filter_user_caps' ), 10, 4 );
	}

	// -------------------------------------------------------------------------
	// Capability filter
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
	 * @param WP_User  $user    The user object.
	 * @return bool[]
	 */
	public function filter_user_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User ) {
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
			// Return real caps unchanged — the grant is no longer active.
			return $allcaps;
		}
		// Merge the temporary role's capabilities on top of the user's real ones.
		global $wp_roles;
		$role = $wp_roles->get_role( $grant['temporary_role'] );
		if ( $role ) {
			$allcaps = array_merge( $allcaps, $role->capabilities );
		}
		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Scheduled event callback
	// -------------------------------------------------------------------------

	/**
	 * Marks a grant as expired; called by its dedicated scheduled event.
	 *
	 * The user's database role is not modified — removing the active grant
	 * record is sufficient because filter_user_caps() will no longer find an
	 * active grant and will return the user's real capabilities unchanged.
	 *
	 * Safe to call multiple times — returns early if the grant is already
	 * resolved.
	 *
	 * @since 1.0.0
	 *
	 * @param string $grant_id UUID of the grant to expire.
	 */
	public function expire_grant( $grant_id ) {
		$grants = $this->all_grants();
		if ( ! isset( $grants[ $grant_id ] ) || 'active' !== $grants[ $grant_id ]['status'] ) {
			return;
		}
		$grants[ $grant_id ]['status']      = 'expired';
		$grants[ $grant_id ]['resolved_at'] = time();
		update_option( self::GRANTS_KEY, $grants, false );
		$this->log( 'expired', $grants[ $grant_id ] );
		// Remove the scheduled event if it somehow still exists.
		$ts = wp_next_scheduled( self::EXPIRE_HOOK, array( $grant_id ) );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EXPIRE_HOOK, array( $grant_id ) );
		}
	}

	// -------------------------------------------------------------------------
	// Grant management
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
	 * @return array|WP_Error Grant record on success, WP_Error on failure.
	 */
	public function grant( $user_id, $new_role, $expires_at, $granted_by ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'User not found.', 't3admin' ) );
		}
		// Supersede any existing active grant for this user.
		$existing = $this->user_active_grant( $user_id );
		if ( $existing ) {
			$this->revoke( $existing['id'], $granted_by, 'superseded' );
		}
		$original      = ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
		$id            = wp_generate_uuid4();
		$entry         = array(
			'id'             => $id,
			'user_id'        => (int) $user_id,
			'original_role'  => $original,
			'temporary_role' => $new_role,
			'granted_by'     => (int) $granted_by,
			'granted_at'     => time(),
			'expires_at'     => (int) $expires_at,
			'status'         => 'active',
		);
		$grants        = $this->all_grants();
		$grants[ $id ] = $entry;
		update_option( self::GRANTS_KEY, $grants, false );
		// Schedule a single event to mark the grant expired at the right moment.
		wp_schedule_single_event( $expires_at, self::EXPIRE_HOOK, array( $id ) );
		$this->log( 'granted', $entry );
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
	public function revoke( $grant_id, $revoked_by, $reason = 'manual' ) {
		$grants = $this->all_grants();
		if ( ! isset( $grants[ $grant_id ] ) || 'active' !== $grants[ $grant_id ]['status'] ) {
			return false;
		}
		$grants[ $grant_id ]['status']        = 'revoked';
		$grants[ $grant_id ]['resolved_at']   = time();
		$grants[ $grant_id ]['revoked_by']    = (int) $revoked_by;
		$grants[ $grant_id ]['revoke_reason'] = $reason;
		update_option( self::GRANTS_KEY, $grants, false );
		$this->log( 'revoked', $grants[ $grant_id ] );
		$ts = wp_next_scheduled( self::EXPIRE_HOOK, array( $grant_id ) );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EXPIRE_HOOK, array( $grant_id ) );
		}
		return true;
	}

	/**
	 * Returns all grants (active, expired, and revoked) from the option store.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	private function all_grants() {
		return (array) get_option( self::GRANTS_KEY, array() );
	}

	/**
	 * Returns only the currently active grants.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	private function active_grants() {
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
	private function user_active_grant( $user_id ) {
		foreach ( $this->active_grants() as $g ) {
			if ( (int) $g['user_id'] === (int) $user_id ) {
				return $g;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Returns the absolute path to the log directory inside wp-content/uploads.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function log_dir() {
		return wp_upload_dir()['basedir'] . '/' . self::LOG_DIR;
	}

	/**
	 * Returns the absolute path to the JSONL log file.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function log_path() {
		return $this->log_dir() . '/' . self::LOG_FILE;
	}

	/**
	 * Creates the log directory and protective files if they do not exist.
	 *
	 * Writes an .htaccess that denies direct HTTP access and an index.php
	 * stub so directory listings reveal nothing.
	 *
	 * @since 1.0.0
	 */
	private function ensure_log_dir() {
		$dir = $this->log_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Require all denied\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	/**
	 * Appends a JSONL entry to the audit log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event One of 'granted', 'revoked', or 'expired'.
	 * @param array  $grant The grant record being logged.
	 */
	private function log( $event, $grant ) {
		$this->ensure_log_dir();
		$entry = array(
			'timestamp'      => gmdate( 'c' ),
			'event'          => $event,
			'grant_id'       => $grant['id'],
			'user_id'        => $grant['user_id'],
			'original_role'  => $grant['original_role'],
			'temporary_role' => $grant['temporary_role'],
			'granted_by'     => $grant['granted_by'],
			'granted_at'     => $grant['granted_at'],
			'expires_at'     => $grant['expires_at'],
		);
		if ( 'granted' !== $event ) {
			$entry['resolved_at'] = $grant['resolved_at'] ?? time();
		}
		if ( isset( $grant['revoked_by'] ) ) {
			$entry['revoked_by']    = $grant['revoked_by'];
			$entry['revoke_reason'] = $grant['revoke_reason'] ?? '';
		}
		// Direct file I/O is intentional: WP_Filesystem requires an admin
		// context and is inappropriate for background log writes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_path(), wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Reads a page of log entries from the JSONL file, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $per_page Entries per page.
	 * @param int $page     1-based page number.
	 * @return array{ entries: array, total: int }
	 */
	private function read_log( $per_page = 50, $page = 1 ) {
		$path = $this->log_path();
		if ( ! file_exists( $path ) ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}
		$total   = count( $lines );
		$lines   = array_reverse( $lines );
		$sliced  = array_slice( $lines, ( $page - 1 ) * $per_page, $per_page );
		$entries = array();
		foreach ( $sliced as $line ) {
			$decoded = json_decode( $line, true );
			if ( $decoded ) {
				$entries[] = $decoded;
			}
		}
		return array(
			'entries' => $entries,
			'total'   => $total,
		);
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	/**
	 * Processes the grant-role form submission (admin-post.php action).
	 *
	 * @since 1.0.0
	 */
	public function handle_grant() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		check_admin_referer( 't3admin_grant' );

		$user_id     = absint( $_POST['t3admin_user_id'] ?? 0 );
		$new_role    = sanitize_key( $_POST['t3admin_role'] ?? '' );
		$expiry_type = sanitize_key( $_POST['t3admin_expiry_type'] ?? 'datetime' );

		if ( ! $user_id || ! $new_role ) {
			wp_safe_redirect( add_query_arg( 't3admin_msg', 'missing', admin_url( 'users.php?page=t3admin' ) ) );
			exit;
		}

		global $wp_roles;
		if ( ! isset( $wp_roles->roles[ $new_role ] ) ) {
			wp_safe_redirect( add_query_arg( 't3admin_msg', 'bad_role', admin_url( 'users.php?page=t3admin' ) ) );
			exit;
		}

		if ( 'datetime' === $expiry_type ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['t3admin_expiry_datetime'] ?? '' ) );
			try {
				$dt         = new DateTimeImmutable( $raw, wp_timezone() );
				$expires_at = $dt->getTimestamp();
			} catch ( Exception $e ) {
				$expires_at = 0;
			}
		} else {
			$duration   = max( 1, absint( $_POST['t3admin_duration'] ?? 1 ) );
			$unit       = sanitize_key( $_POST['t3admin_duration_unit'] ?? 'hours' );
			$mults      = array(
				'minutes' => MINUTE_IN_SECONDS,
				'hours'   => HOUR_IN_SECONDS,
				'days'    => DAY_IN_SECONDS,
			);
			$expires_at = time() + $duration * ( $mults[ $unit ] ?? HOUR_IN_SECONDS );
		}

		if ( time() >= $expires_at ) {
			wp_safe_redirect( add_query_arg( 't3admin_msg', 'past', admin_url( 'users.php?page=t3admin' ) ) );
			exit;
		}

		$result = $this->grant( $user_id, $new_role, $expires_at, get_current_user_id() );
		$msg    = is_wp_error( $result ) ? $result->get_error_code() : 'granted';
		wp_safe_redirect( add_query_arg( 't3admin_msg', $msg, admin_url( 'users.php?page=t3admin' ) ) );
		exit;
	}

	/**
	 * Processes the revoke-grant form submission (admin-post.php action).
	 *
	 * @since 1.0.0
	 */
	public function handle_revoke() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		check_admin_referer( 't3admin_revoke' );
		$grant_id = sanitize_text_field( wp_unslash( $_POST['t3admin_grant_id'] ?? '' ) );
		if ( $grant_id ) {
			$this->revoke( $grant_id, get_current_user_id() );
		}
		wp_safe_redirect( add_query_arg( 't3admin_msg', 'revoked', admin_url( 'users.php?page=t3admin' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the two admin pages under Users in the menu.
	 *
	 * @since 1.0.0
	 */
	public function admin_menu() {
		add_users_page(
			__( 'Temporary Titan Token', 't3admin' ),
			__( 'Temp Roles', 't3admin' ),
			self::CAP,
			't3admin',
			array( $this, 'page_main' )
		);
		add_users_page(
			__( 'T3Admin Logs', 't3admin' ),
			__( 'Temp Role Logs', 't3admin' ),
			self::CAP,
			't3admin-logs',
			array( $this, 'page_logs' )
		);
	}

	/**
	 * Renders the main admin page: grant form and active grants table.
	 *
	 * @since 1.0.0
	 */
	public function page_main() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		global $wp_roles;
		$roles  = $wp_roles->get_names();
		$users  = get_users(
			array(
				'number'  => -1,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		$active = $this->active_grants();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect message.
		$msg  = sanitize_key( $_GET['t3admin_msg'] ?? '' );
		$msgs = array(
			'granted'      => array( 'success', __( 'Temporary role granted successfully.', 't3admin' ) ),
			'revoked'      => array( 'success', __( 'Grant revoked and original role restored.', 't3admin' ) ),
			'missing'      => array( 'error', __( 'Please select a user and role.', 't3admin' ) ),
			'bad_role'     => array( 'error', __( 'Invalid role selected.', 't3admin' ) ),
			'past'         => array( 'error', __( 'Expiry time must be in the future.', 't3admin' ) ),
			'invalid_user' => array( 'error', __( 'User not found.', 't3admin' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Temporary Titan Token', 't3admin' ); ?></h1>
			<p class="description"><em><?php esc_html_e( 'Hold the title of titan, if only for a tick', 't3admin' ); ?></em></p>

			<?php
			if ( $msg && isset( $msgs[ $msg ] ) ) {
				list( $type, $text ) = $msgs[ $msg ];
				?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
					<p><?php echo esc_html( $text ); ?></p>
				</div>
				<?php
			}
			?>

			<h2><?php esc_html_e( 'Grant Temporary Role', 't3admin' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 't3admin_grant' ); ?>
				<input type="hidden" name="action" value="t3admin_grant">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="t3u"><?php esc_html_e( 'User', 't3admin' ); ?></label></th>
						<td>
							<select name="t3admin_user_id" id="t3u" required>
								<option value=""><?php esc_html_e( '— Select a user —', 't3admin' ); ?></option>
								<?php foreach ( $users as $u ) : ?>
									<option value="<?php echo esc_attr( $u->ID ); ?>">
										<?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="t3r"><?php esc_html_e( 'Temporary Role', 't3admin' ); ?></label></th>
						<td>
							<select name="t3admin_role" id="t3r" required>
								<option value=""><?php esc_html_e( '— Select a role —', 't3admin' ); ?></option>
								<?php foreach ( $roles as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( translate_user_role( $label ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Expiry Type', 't3admin' ); ?></th>
						<td>
							<label>
								<input type="radio" name="t3admin_expiry_type" value="datetime" checked>
								<?php esc_html_e( 'Specific date &amp; time', 't3admin' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="radio" name="t3admin_expiry_type" value="duration">
								<?php esc_html_e( 'Duration from now', 't3admin' ); ?>
							</label>
						</td>
					</tr>
					<tr id="t3_dt">
						<th><label for="t3dt"><?php esc_html_e( 'Expires At', 't3admin' ); ?></label></th>
						<td>
							<input type="datetime-local" name="t3admin_expiry_datetime" id="t3dt">
							<p class="description">
								<?php
								printf(
									/* translators: %s: timezone name */
									esc_html__( 'Site timezone: %s', 't3admin' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr id="t3_dur" style="display:none">
						<th><label for="t3d"><?php esc_html_e( 'Duration', 't3admin' ); ?></label></th>
						<td>
							<input type="number" name="t3admin_duration" id="t3d" min="1" value="1" style="width:70px">
							<select name="t3admin_duration_unit">
								<option value="minutes"><?php esc_html_e( 'Minutes', 't3admin' ); ?></option>
								<option value="hours" selected><?php esc_html_e( 'Hours', 't3admin' ); ?></option>
								<option value="days"><?php esc_html_e( 'Days', 't3admin' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Grant Temporary Role', 't3admin' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Active Grants', 't3admin' ); ?></h2>
			<?php if ( empty( $active ) ) : ?>
				<p><?php esc_html_e( 'No active grants.', 't3admin' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped users">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Original Role', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Temp Role', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Granted By', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Granted At', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Expires At', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Remaining', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Action', 't3admin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $active as $g ) :
							$u       = get_user_by( 'id', $g['user_id'] );
							$granter = get_user_by( 'id', $g['granted_by'] );
							$rem     = max( 0, $g['expires_at'] - time() );
							$h       = (int) floor( $rem / 3600 );
							$m       = (int) floor( ( $rem % 3600 ) / 60 );
							?>
						<tr>
							<td>
								<?php
								if ( $u ) {
									echo esc_html( $u->display_name );
								} else {
									/* translators: %d: user ID */
									echo esc_html( sprintf( __( 'User #%d', 't3admin' ), $g['user_id'] ) );
								}
								?>
							</td>
							<td><?php echo esc_html( $g['original_role'] ); ?></td>
							<td><?php echo esc_html( $g['temporary_role'] ); ?></td>
							<td>
								<?php
								if ( $granter ) {
									echo esc_html( $granter->display_name );
								} else {
									/* translators: %d: user ID */
									echo esc_html( sprintf( __( 'User #%d', 't3admin' ), $g['granted_by'] ) );
								}
								?>
							</td>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $g['granted_at'] ) ); ?></td>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $g['expires_at'] ) ); ?></td>
							<td>
								<?php
								if ( $rem > 0 ) {
									/* translators: 1: hours remaining, 2: minutes remaining */
									echo esc_html( sprintf( __( '%1$dh %2$dm', 't3admin' ), $h, $m ) );
								} else {
									echo '<em>' . esc_html__( 'Expiring&hellip;', 't3admin' ) . '</em>';
								}
								?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 't3admin_revoke' ); ?>
									<input type="hidden" name="action" value="t3admin_revoke">
									<input type="hidden" name="t3admin_grant_id" value="<?php echo esc_attr( $g['id'] ); ?>">
									<button type="submit" class="button button-small"
										onclick="return confirm('<?php esc_attr_e( 'Revoke this grant and restore the original role?', 't3admin' ); ?>')">
										<?php esc_html_e( 'Revoke', 't3admin' ); ?>
									</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:1.5em">
				<a href="<?php echo esc_url( admin_url( 'users.php?page=t3admin-logs' ) ); ?>" class="button">
					<?php esc_html_e( 'View Access Logs', 't3admin' ); ?>
				</a>
			</p>
		</div>
		<script>
		(function () {
			var dtRow  = document.getElementById('t3_dt');
			var durRow = document.getElementById('t3_dur');
			document.querySelectorAll('input[name="t3admin_expiry_type"]').forEach(function (r) {
				r.addEventListener('change', function () {
					dtRow.style.display  = this.value === 'datetime' ? '' : 'none';
					durRow.style.display = this.value === 'duration' ? '' : 'none';
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * Renders the paginated access log viewer page.
	 *
	 * @since 1.0.0
	 */
	public function page_logs() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter.
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$result  = $this->read_log( $per_page, $page );
		$entries = $result['entries'];
		$total   = $result['total'];
		$pages   = (int) ceil( $total / $per_page );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'T3Admin — Access Logs', 't3admin' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'users.php?page=t3admin' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to Grants', 't3admin' ); ?>
				</a>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No log entries yet.', 't3admin' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: entries on page, 2: total entries */
						esc_html__( 'Showing %1$d of %2$d entries (newest first).', 't3admin' ),
						count( $entries ),
						(int) $total
					);
					?>
				</p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:165px"><?php esc_html_e( 'Timestamp', 't3admin' ); ?></th>
							<th style="width:70px"><?php esc_html_e( 'Event', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'User', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Role Change', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Actor', 't3admin' ); ?></th>
							<th><?php esc_html_e( 'Expires At', 't3admin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $entries as $e ) :
							$user  = isset( $e['user_id'] ) ? get_user_by( 'id', $e['user_id'] ) : null;
							$aid   = $e['revoked_by'] ?? $e['granted_by'] ?? null;
							$actor = $aid ? get_user_by( 'id', $aid ) : null;
							?>
						<tr>
							<td><?php echo esc_html( $e['timestamp'] ?? '' ); ?></td>
							<td>
								<span class="t3a-badge t3a-<?php echo esc_attr( $e['event'] ?? '' ); ?>">
									<?php echo esc_html( $e['event'] ?? '' ); ?>
								</span>
							</td>
							<td>
								<?php
								if ( $user ) {
									echo esc_html( $user->display_name );
								} else {
									echo esc_html( 'ID:' . ( $e['user_id'] ?? '?' ) );
								}
								?>
							</td>
							<td>
								<?php
								if ( isset( $e['original_role'], $e['temporary_role'] ) ) {
									echo esc_html( $e['original_role'] ) . ' &rarr; ' . esc_html( $e['temporary_role'] );
								}
								?>
							</td>
							<td>
								<?php
								if ( $actor ) {
									echo esc_html( $actor->display_name );
								} else {
									esc_html_e( 'System', 't3admin' );
								}
								?>
							</td>
							<td>
								<?php
								if ( isset( $e['expires_at'] ) ) {
									echo esc_html( wp_date( 'Y-m-d H:i', $e['expires_at'] ) );
								}
								?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'    => add_query_arg( 'paged', '%#%' ),
										'format'  => '',
										'current' => $page,
										'total'   => $pages,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<style>
		.t3a-badge { display:inline-block; padding:1px 7px; border-radius:3px; font-size:11px; font-weight:600; text-transform:uppercase; }
		.t3a-granted { background:#d4edda; color:#155724; }
		.t3a-revoked { background:#fff3cd; color:#856404; }
		.t3a-expired { background:#f8d7da; color:#721c24; }
		</style>
		<?php
	}
}

Temporary_Titan_Token::instance();
