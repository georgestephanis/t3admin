<?php
/**
 * Admin UI: menu registration, tab pages, form handlers, Users list column.
 *
 * @package t3admin
 * @since   1.0.0
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all wp-admin integration for the plugin.
 *
 * Registers a single Users submenu page that hosts both the grant form
 * (default tab) and the audit-log viewer (logs tab).  The log page is not
 * given its own sidebar entry.
 *
 * @since 1.0.0
 */
class Admin {

	const CAP = 'promote_users';

	/**
	 * Grants instance.
	 *
	 * @var Grants
	 */
	private $grants;

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
	 * @param Grants $grants Grants instance.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Grants $grants, Logger $logger ) {
		$this->grants = $grants;
		$this->logger = $logger;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function setup_hooks(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'network_admin_menu' ) );
		add_action( 'admin_post_t3admin_grant', array( $this, 'handle_grant' ) );
		add_action( 'admin_post_t3admin_revoke', array( $this, 'handle_revoke' ) );
		add_action( 'admin_head', array( $this, 'admin_styles' ) );
		add_filter( 'manage_users_columns', array( $this, 'manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_users_role_column' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the plugin's admin page under Users.
	 *
	 * Only one page appears in the sidebar (Temp Roles); the logs view is
	 * a tab within it.
	 *
	 * @since 1.0.0
	 */
	public function admin_menu(): void {
		if ( is_multisite() && is_network_admin() ) {
			return;
		}

		add_users_page(
			__( 'Temporary Titan Token', 't3admin' ),
			__( 'Temp Roles', 't3admin' ),
			self::CAP,
			't3admin',
			array( $this, 'page_main' )
		);
	}

	/**
	 * Registers the plugin's admin page in Network Admin.
	 *
	 * @since 1.0.0
	 */
	public function network_admin_menu(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_users_page(
			__( 'Temporary Titan Token', 't3admin' ),
			__( 'Temp Roles', 't3admin' ),
			self::CAP,
			't3admin',
			array( $this, 'page_main' )
		);
	}

	/**
	 * Returns whether current request runs in Network Admin.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_network_context(): bool {
		return is_multisite() && is_network_admin();
	}

	/**
	 * Returns the base page URL for the current admin context.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function page_url(): string {
		if ( $this->is_network_context() ) {
			return network_admin_url( 'users.php?page=t3admin' );
		}
		return admin_url( 'users.php?page=t3admin' );
	}

	/**
	 * Returns the admin-post URL for the current admin context.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function post_url(): string {
		if ( $this->is_network_context() ) {
			return network_admin_url( 'admin-post.php' );
		}
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Redirects back to this plugin page with an optional message code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $msg Message key.
	 */
	private function redirect_with_message( string $msg ): void {
		$url = $this->page_url();
		if ( '' !== $msg ) {
			$url = add_query_arg( 't3admin_msg', $msg, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	// -------------------------------------------------------------------------
	// Inline styles
	// -------------------------------------------------------------------------

	/**
	 * Outputs plugin CSS in the admin head.
	 *
	 * @since 1.0.0
	 */
	public function admin_styles(): void {
		?>
		<style>
		.t3a-badge { display:inline-block; padding:1px 7px; border-radius:3px; font-size:11px; font-weight:600; text-transform:uppercase; }
		.t3a-granted { background:#d4edda; color:#155724; }
		.t3a-revoked { background:#fff3cd; color:#856404; }
		.t3a-expired { background:#f8d7da; color:#721c24; }
		.t3a-temp-role { display:block; color:#646970; font-size:11px; font-style:italic; cursor:help; }
		</style>
		<?php
	}

	// -------------------------------------------------------------------------
	// Users list table column
	// -------------------------------------------------------------------------

	/**
	 * Replaces the built-in Role column with a custom one that shows temp grants.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function manage_users_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'role' === $key ) {
				$new['t3admin_role'] = $label;
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	/**
	 * Renders the custom role column cell.
	 *
	 * Shows the user's real role(s) plus, if a temp grant is active, a second
	 * line with the temporary role and a tooltip showing the exact expiry time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output      Current cell output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID for this row.
	 * @return string
	 */
	public function render_users_role_column( string $output, string $column_name, int $user_id ): string {
		if ( 't3admin_role' !== $column_name ) {
			return $output;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return $output;
		}
		global $wp_roles;
		$real_roles = array();
		foreach ( $user->roles as $slug ) {
			$name         = isset( $wp_roles->roles[ $slug ]['name'] ) ? $wp_roles->roles[ $slug ]['name'] : $slug;
			$real_roles[] = translate_user_role( $name );
		}
		$out = esc_html( implode( ', ', $real_roles ) );

		$grant = $this->grants->user_active_grant( $user_id );
		if ( $grant && is_multisite() && Grants::SUPER_ADMIN_ROLE !== $grant['temporary_role'] ) {
			$grant_blog = isset( $grant['blog_id'] ) ? (int) $grant['blog_id'] : 0;
			if ( 0 !== $grant_blog && get_current_blog_id() !== $grant_blog ) {
				$grant = null;
			}
		}
		if ( $grant ) {
			$rem = max( 0, $grant['expires_at'] - time() );
			$h   = (int) floor( $rem / 3600 );
			$m   = (int) floor( ( $rem % 3600 ) / 60 );
			if ( $rem > 0 ) {
				/* translators: 1: hours remaining, 2: minutes remaining */
				$remaining = sprintf( __( '%1$dh %2$dm', 't3admin' ), $h, $m );
			} else {
				$remaining = __( 'expiring&hellip;', 't3admin' );
			}
			$expires_label = sprintf(
				/* translators: %s: expiry date and time */
				__( 'Expires: %s', 't3admin' ),
				wp_date( 'Y-m-d H:i', $grant['expires_at'] )
			);
			$out .= '<span class="t3a-temp-role" title="' . esc_attr( $expires_label ) . '">'
				. '&#8593; ' . esc_html( $this->grants->role_label( $grant['temporary_role'] ) )
				. ' (' . esc_html( $remaining ) . ')'
				. '</span>';
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Page: main (grants tab + logs tab)
	// -------------------------------------------------------------------------

	/**
	 * Renders the main admin page, routing to the active tab.
	 *
	 * @since 1.0.0
	 */
	public function page_main(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter, read-only routing.
		$tab      = sanitize_key( $_GET['tab'] ?? 'grants' );
		$base_url = $this->page_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Temporary Titan Token', 't3admin' ); ?></h1>
			<p class="description"><em><?php esc_html_e( 'Hold the title of titan, if only for a tick', 't3admin' ); ?></em></p>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secondary menu', 't3admin' ); ?>">
				<a href="<?php echo esc_url( $base_url ); ?>"
					class="nav-tab<?php echo ( 'grants' === $tab ) ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Grants', 't3admin' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'logs', $base_url ) ); ?>"
					class="nav-tab<?php echo ( 'logs' === $tab ) ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Access Logs', 't3admin' ); ?>
				</a>
			</nav>

			<?php
			if ( 'logs' === $tab ) {
				$this->render_logs_tab();
			} else {
				$this->render_grants_tab();
			}
			?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Grants tab
	// -------------------------------------------------------------------------

	/**
	 * Renders the grant form and active grants table.
	 *
	 * @since 1.0.0
	 */
	private function render_grants_tab(): void {
		global $wp_roles;
		$roles              = $wp_roles->get_names();
		$is_network_context = $this->is_network_context();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search term.
		$user_query = sanitize_text_field( wp_unslash( $_GET['t3admin_user_q'] ?? '' ) );
		$user_args  = array(
			'number'  => 200,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		);
		if ( '' !== $user_query ) {
			$user_args['search']         = '*' . $user_query . '*';
			$user_args['search_columns'] = array( 'display_name', 'user_login', 'user_email' );
		}
		$users  = get_users( $user_args );
		$active = $this->grants->active_grants();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect message.
		$msg  = sanitize_key( $_GET['t3admin_msg'] ?? '' );
		$msgs = array(
			'granted'      => array( 'success', __( 'Temporary role granted successfully.', 't3admin' ) ),
			'revoked'      => array( 'success', __( 'Grant revoked and original role restored.', 't3admin' ) ),
			'missing'      => array( 'error', __( 'Please select a user and role.', 't3admin' ) ),
			'bad_role'     => array( 'error', __( 'Invalid role selected.', 't3admin' ) ),
			'past'         => array( 'error', __( 'Expiry time must be in the future.', 't3admin' ) ),
			'invalid_user' => array( 'error', __( 'User not found.', 't3admin' ) ),
			'no_super'     => array( 'error', __( 'Only Super Admins can grant Super Admin status.', 't3admin' ) ),
			'bad_scope'    => array( 'error', __( 'Invalid scope selected for this admin context.', 't3admin' ) ),
		);
		if ( $msg && isset( $msgs[ $msg ] ) ) {
			list( $type, $text ) = $msgs[ $msg ];
			?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
				<p><?php echo esc_html( $text ); ?></p>
			</div>
			<?php
		}
		?>

		<form method="get" action="<?php echo esc_url( $this->page_url() ); ?>">
			<input type="hidden" name="page" value="t3admin">
			<input type="hidden" name="tab" value="grants">
			<label for="t3_user_q"><strong><?php esc_html_e( 'Find User', 't3admin' ); ?></strong></label>
			<input type="search" id="t3_user_q" name="t3admin_user_q" value="<?php echo esc_attr( $user_query ); ?>" placeholder="<?php esc_attr_e( 'Search by name, login, or email', 't3admin' ); ?>" style="min-width:280px">
			<?php submit_button( __( 'Search', 't3admin' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $user_query ) : ?>
				<a class="button button-link" href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Clear', 't3admin' ); ?></a>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Shows up to 200 matching users. Refine your search to narrow results.', 't3admin' ); ?>
			</p>
		</form>

		<h2><?php esc_html_e( 'Grant Temporary Role', 't3admin' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>">
			<?php wp_nonce_field( 't3admin_grant' ); ?>
			<input type="hidden" name="action" value="t3admin_grant">
			<table class="form-table" role="presentation">
				<?php if ( is_multisite() && $is_network_context ) : ?>
				<tr>
					<th><label for="t3scope"><?php esc_html_e( 'Grant Scope', 't3admin' ); ?></label></th>
					<td>
						<select name="t3admin_scope" id="t3scope">
							<option value="<?php echo esc_attr( Grants::SCOPE_SITE ); ?>"><?php esc_html_e( 'Single Site', 't3admin' ); ?></option>
							<option value="<?php echo esc_attr( Grants::SCOPE_NETWORK ); ?>"><?php esc_html_e( 'Whole Network', 't3admin' ); ?></option>
							<option value="<?php echo esc_attr( Grants::SCOPE_SUPER ); ?>"><?php esc_html_e( 'Super Admin (Temporary)', 't3admin' ); ?></option>
						</select>
					</td>
				</tr>
				<?php endif; ?>
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
				<tr id="t3_role_row">
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
						<?php if ( is_multisite() && $is_network_context ) : ?>
						<p class="description" id="t3_super_note" style="display:none">
							<?php esc_html_e( 'Super Admin scope ignores role selection and grants temporary Super Admin access.', 't3admin' ); ?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( is_multisite() && $is_network_context ) : ?>
				<tr id="t3_site">
					<th><label for="t3s"><?php esc_html_e( 'Site', 't3admin' ); ?></label></th>
					<td>
						<select name="t3admin_blog_id" id="t3s">
							<?php
							foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
								$site_name = get_blog_option( $site->blog_id, 'blogname' );
								if ( ! $site_name ) {
									/* translators: %d: site ID */
									$site_name = sprintf( __( 'Site #%d', 't3admin' ), $site->blog_id );
								}
								printf(
									'<option value="%d"%s>%s</option>',
									esc_attr( $site->blog_id ),
									selected( $site->blog_id, get_current_blog_id(), false ),
									esc_html( $site_name . ' (' . $site->domain . $site->path . ')' )
								);
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'The site this temporary role applies to.', 't3admin' ); ?></p>
					</td>
				</tr>
				<?php elseif ( is_multisite() ) : ?>
				<tr>
					<th><?php esc_html_e( 'Site', 't3admin' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: site name */
								esc_html__( 'This panel grants roles for the current site only: %s', 't3admin' ),
								esc_html( get_bloginfo( 'name' ) )
							);
							?>
						</p>
					</td>
				</tr>
				<?php endif; ?>
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
						<?php if ( is_multisite() ) : ?>
						<th><?php esc_html_e( 'Site', 't3admin' ); ?></th>
						<?php endif; ?>
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
						<?php if ( is_multisite() ) : ?>
						<td>
							<?php
							if ( Grants::SUPER_ADMIN_ROLE === $g['temporary_role'] ) {
								esc_html_e( 'All Sites', 't3admin' );
							} elseif ( ! empty( $g['blog_id'] ) ) {
								$site_name = get_blog_option( $g['blog_id'], 'blogname' );
								echo $site_name
									? esc_html( $site_name )
									/* translators: %d: site ID */
									: esc_html( sprintf( __( 'Site #%d', 't3admin' ), $g['blog_id'] ) );
							} else {
								esc_html_e( 'All Sites', 't3admin' );
							}
							?>
						</td>
						<?php endif; ?>
						<td><?php echo esc_html( $this->grants->role_label( $g['original_role'] ) ); ?></td>
						<td><?php echo esc_html( $this->grants->role_label( $g['temporary_role'] ) ); ?></td>
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
							<form method="post" action="<?php echo esc_url( $this->post_url() ); ?>">
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

		<script>
		(function () {
			var dtRow      = document.getElementById('t3_dt');
			var durRow     = document.getElementById('t3_dur');
			var siteRow    = document.getElementById('t3_site');
			var scopeEl    = document.getElementById('t3scope');
			var roleRow    = document.getElementById('t3_role_row');
			var roleEl     = document.getElementById('t3r');
			var superNote  = document.getElementById('t3_super_note');
			document.querySelectorAll('input[name="t3admin_expiry_type"]').forEach(function (r) {
				r.addEventListener('change', function () {
					dtRow.style.display  = this.value === 'datetime' ? '' : 'none';
					durRow.style.display = this.value === 'duration' ? '' : 'none';
				});
			});
			if ( scopeEl ) {
				function updateScopeControls() {
					var isSuperScope = scopeEl.value === '<?php echo esc_js( Grants::SCOPE_SUPER ); ?>';
					var isSiteScope  = scopeEl.value === '<?php echo esc_js( Grants::SCOPE_SITE ); ?>';

					if ( siteRow ) {
						siteRow.style.display = isSiteScope ? '' : 'none';
					}
					if ( roleRow ) {
						roleRow.style.display = isSuperScope ? 'none' : '';
					}
					if ( roleEl ) {
						roleEl.required = !isSuperScope;
					}
					if ( superNote ) {
						superNote.style.display = isSuperScope ? '' : 'none';
					}
				}

				scopeEl.addEventListener( 'change', updateScopeControls );
				updateScopeControls();
			}
		}());
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Logs tab
	// -------------------------------------------------------------------------

	/**
	 * Renders the paginated audit-log tab using WP_List_Table.
	 *
	 * @since 1.0.0
	 */
	private function render_logs_tab(): void {
		$table = new Log_List_Table( $this->logger, $this->grants );
		$table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="t3admin">
			<input type="hidden" name="tab" value="logs">
			<?php $table->display(); ?>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	/**
	 * Processes the grant-role form submission (admin-post.php action).
	 *
	 * @since 1.0.0
	 */
	public function handle_grant(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		check_admin_referer( 't3admin_grant' );

		$user_id       = absint( wp_unslash( $_POST['t3admin_user_id'] ?? 0 ) );
		$new_role      = sanitize_key( wp_unslash( $_POST['t3admin_role'] ?? '' ) );
		$expiry_type   = sanitize_key( wp_unslash( $_POST['t3admin_expiry_type'] ?? 'datetime' ) );
		$requested_raw = sanitize_key( wp_unslash( $_POST['t3admin_scope'] ?? Grants::SCOPE_SITE ) );
		$scope         = Grants::SCOPE_SITE;

		if ( is_multisite() && $this->is_network_context() ) {
			if ( in_array( $requested_raw, array( Grants::SCOPE_SITE, Grants::SCOPE_NETWORK, Grants::SCOPE_SUPER ), true ) ) {
				$scope = $requested_raw;
			} else {
				$this->redirect_with_message( 'bad_scope' );
			}
		} elseif ( is_multisite() && Grants::SCOPE_SITE !== $requested_raw ) {
			$this->redirect_with_message( 'bad_scope' );
		}

		if ( ! $user_id || ( Grants::SCOPE_SUPER !== $scope && ! $new_role ) ) {
			$this->redirect_with_message( 'missing' );
		}

		if ( Grants::SCOPE_SUPER === $scope ) {
			if ( ! is_multisite() || ! $this->is_network_context() || ! is_super_admin() ) {
				$this->redirect_with_message( 'no_super' );
			}
			$new_role = Grants::SUPER_ADMIN_ROLE;
		} else {
			global $wp_roles;
			if ( Grants::SUPER_ADMIN_ROLE === $new_role || ! isset( $wp_roles->roles[ $new_role ] ) ) {
				$this->redirect_with_message( 'bad_role' );
			}
		}

		if ( 'datetime' === $expiry_type ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['t3admin_expiry_datetime'] ?? '' ) );
			try {
				$dt         = new \DateTimeImmutable( $raw, wp_timezone() );
				$expires_at = $dt->getTimestamp();
			} catch ( \Exception $e ) {
				$expires_at = 0;
			}
		} else {
			$duration   = max( 1, absint( wp_unslash( $_POST['t3admin_duration'] ?? 1 ) ) );
			$unit       = sanitize_key( wp_unslash( $_POST['t3admin_duration_unit'] ?? 'hours' ) );
			$mults      = array(
				'minutes' => MINUTE_IN_SECONDS,
				'hours'   => HOUR_IN_SECONDS,
				'days'    => DAY_IN_SECONDS,
			);
			$expires_at = time() + $duration * ( $mults[ $unit ] ?? HOUR_IN_SECONDS );
		}

		if ( time() >= $expires_at ) {
			$this->redirect_with_message( 'past' );
		}

		$blog_id = 0;
		if ( is_multisite() ) {
			if ( Grants::SCOPE_SITE === $scope ) {
				if ( $this->is_network_context() ) {
					$blog_id = absint( wp_unslash( $_POST['t3admin_blog_id'] ?? get_current_blog_id() ) );
					if ( ! get_site( $blog_id ) ) {
						$blog_id = get_current_blog_id();
					}
				} else {
					$blog_id = get_current_blog_id();
				}
			} elseif ( ! $this->is_network_context() ) {
				$this->redirect_with_message( 'bad_scope' );
			}
		}

		$result = $this->grants->grant( $user_id, $new_role, $expires_at, get_current_user_id(), $blog_id, $scope );
		$msg    = is_wp_error( $result ) ? $result->get_error_code() : 'granted';
		$this->redirect_with_message( $msg );
	}

	/**
	 * Processes the revoke-grant form submission (admin-post.php action).
	 *
	 * @since 1.0.0
	 */
	public function handle_revoke(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 't3admin' ) );
		}
		check_admin_referer( 't3admin_revoke' );
		$grant_id = sanitize_text_field( wp_unslash( $_POST['t3admin_grant_id'] ?? '' ) );
		if ( $grant_id ) {
			$this->grants->revoke( $grant_id, get_current_user_id() );
		}
		$this->redirect_with_message( 'revoked' );
	}
}
