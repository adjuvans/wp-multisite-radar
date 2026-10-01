<?php
/**
 * Supprime toutes les données de Multisite Radar du réseau.
 *
 * @package MultisiteRadar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_multisite() ) {
	return;
}

global $wpdb;

foreach ( [ 'msradar_sites', 'msradar_site_extensions' ] as $msradar_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $msradar_table ) );
}

$msradar_network_options = [ 'msradar_settings', 'msradar_db_version', 'msradar_last_full_scan', 'msradar_legacy_migrated', 'msradar_legacy_aliases', 'msradar_legacy_menu_cursor' ];
$msradar_network_ids     = get_networks(
	[
		'fields' => 'ids',
		'number' => 0,
	]
);
foreach ( $msradar_network_ids as $msradar_network_id ) {
	foreach ( $msradar_network_options as $msradar_option ) {
		delete_network_option( (int) $msradar_network_id, $msradar_option );
	}
}

delete_metadata( 'user', 0, 'msradar_view_prefs', '', true );

$msradar_hooks    = [ 'msradar_probe', 'msradar_process_queue', 'msradar_process_queue_continue', 'msradar_daily', 'msradar_recompute_alerts', 'msradar_legacy_menu_batch' ];
$msradar_site_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );
foreach ( $msradar_site_ids as $msradar_site_id ) {
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name IN (%s, %s)',
			$wpdb->get_blog_prefix( (int) $msradar_site_id ) . 'options',
			'msradar_registry',
			'msradar_scan_lock'
		)
	);

	switch_to_blog( (int) $msradar_site_id );
	foreach ( $msradar_hooks as $msradar_hook ) {
		wp_clear_scheduled_hook( $msradar_hook );
	}
	restore_current_blog();
}
