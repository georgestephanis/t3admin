<?php
/**
 * WP_List_Table subclass for the audit log viewer.
 *
 * @package t3admin
 * @since   1.0.0
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders paginated JSONL audit-log entries in a standard WordPress list table.
 *
 * @since 1.0.0
 */
class Log_List_Table extends \WP_List_Table {

	const PER_PAGE = 50;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Grants instance (used for role labels).
	 *
	 * @var Grants
	 */
	private $grants;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Logger $logger Logger instance.
	 * @param Grants $grants Grants instance.
	 */
	public function __construct( Logger $logger, Grants $grants ) {
		parent::__construct(
			array(
				'singular' => 'log_entry',
				'plural'   => 'log_entries',
				'ajax'     => false,
			)
		);
		$this->logger = $logger;
		$this->grants = $grants;
	}

	/**
	 * Returns the list of columns.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'timestamp'   => __( 'Timestamp', 't3admin' ),
			'event'       => __( 'Event', 't3admin' ),
			'user'        => __( 'User', 't3admin' ),
			'role_change' => __( 'Role Change', 't3admin' ),
			'actor'       => __( 'Actor', 't3admin' ),
			'expires_at'  => __( 'Expires At', 't3admin' ),
		);
	}

	/**
	 * Loads log data and sets up pagination.
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter.
		$page        = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$result      = $this->logger->read_log( self::PER_PAGE, $page );
		$this->items = $result['entries'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Renders a cell for a column that has no dedicated method.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $item        Log entry array.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'timestamp':
				return esc_html( $item['timestamp'] ?? '' );

			case 'user':
				$u = isset( $item['user_id'] ) ? get_user_by( 'id', $item['user_id'] ) : null;
				return $u ? esc_html( $u->display_name ) : esc_html( 'ID:' . ( $item['user_id'] ?? '?' ) );

			case 'role_change':
				if ( isset( $item['original_role'], $item['temporary_role'] ) ) {
					return esc_html( $this->grants->role_label( $item['original_role'] ) )
						. ' &rarr; '
						. esc_html( $this->grants->role_label( $item['temporary_role'] ) );
				}
				return '';

			case 'actor':
				$aid   = $item['revoked_by'] ?? $item['granted_by'] ?? null;
				$actor = $aid ? get_user_by( 'id', $aid ) : null;
				return $actor ? esc_html( $actor->display_name ) : esc_html__( 'System', 't3admin' );

			case 'expires_at':
				return isset( $item['expires_at'] ) ? esc_html( wp_date( 'Y-m-d H:i', $item['expires_at'] ) ) : '';

			default:
				return '';
		}
	}

	/**
	 * Renders the Event column with a coloured badge.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Log entry array.
	 * @return string
	 */
	public function column_event( $item ) {
		$event = $item['event'] ?? '';
		return '<span class="t3a-badge t3a-' . esc_attr( $event ) . '">' . esc_html( $event ) . '</span>';
	}

	/**
	 * Renders the message shown when there are no log entries.
	 *
	 * @since 1.0.0
	 */
	public function no_items() {
		esc_html_e( 'No log entries yet.', 't3admin' );
	}
}
