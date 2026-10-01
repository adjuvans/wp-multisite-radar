<?php
/**
 * Plugin Name: Multisite Radar demo CPT
 * Description: Local fixture for Multisite Radar: registers a "Demo events" post type.
 */
add_action(
	'init',
	static function () {
		register_post_type( 'demo_event', [ 'label' => 'Demo events', 'public' => true ] );
	}
);
