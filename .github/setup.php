<?php
/**
 * WordPress Playground demo setup.
 *
 * Creates two sample users so the grant form has interesting targets.
 * Loaded by the runPHP step in blueprint.json.
 */

require_once '/wordpress/wp-load.php';

$demo_users = array(
	array(
		'login'        => 'alice',
		'email'        => 'alice@example.com',
		'display_name' => 'Alice (Subscriber)',
		'role'         => 'subscriber',
	),
	array(
		'login'        => 'bob',
		'email'        => 'bob@example.com',
		'display_name' => 'Bob (Author)',
		'role'         => 'author',
	),
);

foreach ( $demo_users as $demo ) {
	$user_id = wp_create_user( $demo['login'], 'password', $demo['email'] );
	if ( is_wp_error( $user_id ) ) {
		continue;
	}
	$user = get_user_by( 'id', $user_id );
	$user->set_role( $demo['role'] );
	wp_update_user(
		array(
			'ID'           => $user_id,
			'display_name' => $demo['display_name'],
		)
	);
}
