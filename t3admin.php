<?php
/**
 * Plugin Name:  Temporary Titan Token
 * Description:  Hold the title of titan, if only for a tick
 * Version:      1.0.0
 * Network:      true
 * Text Domain:  t3admin
 * Requires PHP: 7.4
 *
 * @package t3admin
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-grants.php';
require_once __DIR__ . '/includes/class-log-list-table.php';
require_once __DIR__ . '/includes/class-admin.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
}

const VERSION = '1.0.0';

/**
 * Returns the singleton Logger instance.
 *
 * @since 1.0.0
 * @return Logger
 */
function logger(): Logger {
	static $instance;
	if ( ! isset( $instance ) ) {
		$instance = new Logger();
	}
	return $instance;
}

/**
 * Returns the singleton Grants instance.
 *
 * @since 1.0.0
 * @return Grants
 */
function grants(): Grants {
	static $instance;
	if ( ! isset( $instance ) ) {
		$instance = new Grants( logger() );
	}
	return $instance;
}

/**
 * Returns the singleton Admin instance.
 *
 * @since 1.0.0
 * @return Admin
 */
function admin_ui(): Admin {
	static $instance;
	if ( ! isset( $instance ) ) {
		$instance = new Admin( grants(), logger() );
	}
	return $instance;
}

register_activation_hook(
	__FILE__,
	function () {
		logger()->ensure_log_dir();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		foreach ( grants()->all_grants() as $grant ) {
			if ( 'active' === $grant['status'] ) {
				$ts = wp_next_scheduled( Grants::EXPIRE_HOOK, array( $grant['id'] ) );
				if ( $ts ) {
					wp_unschedule_event( $ts, Grants::EXPIRE_HOOK, array( $grant['id'] ) );
				}
			}
		}
	}
);

add_action(
	'init',
	function () {
		grants()->setup_hooks();
		admin_ui()->setup_hooks();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register();
		}
	}
);
