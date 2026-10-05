<?php
/**
 * Supprime toutes les données de Multisite Radar du réseau.
 *
 * @package MultisiteRadar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drops the plugin's own tables and deletes its data on uninstall.

if ( ! is_multisite() ) {
	return;
}

global $wpdb;

foreach ( [ 'msradar_sites', 'msradar_site_extensions', 'msradar_events', 'msradar_snapshots' ] as $msradar_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $msradar_table ) );
}

$msradar_network_options = [ 'msradar_settings', 'msradar_db_version', 'msradar_last_full_scan', 'msradar_legacy_migrated', 'msradar_legacy_aliases', 'msradar_legacy_menu_cursor', 'msradar_recompute_cursor', 'msradar_network_state', 'msradar_digest_sent' ];
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

	// Cache du module « menu des sites », propre à chaque réseau.
	delete_network_option( (int) $msradar_network_id, '_site_transient_msradar_sites_list_' . $msradar_network_id );
	delete_network_option( (int) $msradar_network_id, '_site_transient_timeout_msradar_sites_list_' . $msradar_network_id );
	wp_cache_delete( 'msradar_sites_list_' . $msradar_network_id, 'site-transient' );
}

delete_metadata( 'user', 0, 'msradar_view_prefs', '', true );

// Par lots de 500 sites. Seuls les sites dont l'option cron brute mentionne un de nos événements sont basculés :
// lire l'option cron charge tout l'autoload du site, et le cache d'exécution est vidé après chaque bascule.
$msradar_hooks = [ 'msradar_probe', 'msradar_process_queue', 'msradar_process_queue_continue', 'msradar_daily', 'msradar_recompute_alerts', 'msradar_legacy_menu_batch' ];
$msradar_after = 0;
do {
	$msradar_site_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT 500', $wpdb->blogs, $msradar_after ) ) );
	$msradar_fetched  = count( $msradar_site_ids );
	foreach ( $msradar_site_ids as $msradar_site_id ) {
		$msradar_options_table = $wpdb->get_blog_prefix( $msradar_site_id ) . 'options';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN (%s, %s)', $msradar_options_table, 'msradar_registry', 'msradar_scan_lock' ) );

		$msradar_cron = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $msradar_options_table, 'cron' ) );
		if ( is_string( $msradar_cron ) && false !== strpos( $msradar_cron, 'msradar_' ) ) {
			switch_to_blog( $msradar_site_id );
			foreach ( $msradar_hooks as $msradar_hook ) {
				wp_clear_scheduled_hook( $msradar_hook );
			}
			restore_current_blog();
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		$msradar_after = $msradar_site_id;
	}
} while ( 500 === $msradar_fetched );
