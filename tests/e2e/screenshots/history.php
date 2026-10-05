<?php
/**
 * Historique de démonstration des captures (npm run screenshots:seed) : 31 jours de relevés, dérivés des mesures du
 * jour avec une croissance régulière, et une dizaine de changements datés. Rejouable : réécrit l'historique du réseau.
 *
 * @package MultisiteRadar
 */

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Plugin;

( static function (): void {
	global $wpdb;
	$plugin  = Plugin::instance();
	$network = get_current_network_id();
	$now     = time();
	$today   = gmdate( 'Y-m-d', $now );
	$site    = static function ( string $slug ): int {
		return (int) get_id_from_blogname( $slug );
	};

	for ( $days = 30; $days >= 0; $days-- ) {
		$plugin->snapshots()->capture( $network, gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS ) );
	}
	// Les jours passés ont un peu moins de contenus, de médias et de comptes qu'aujourd'hui.
	// users_count est non signé : le CAST évite un dépassement de la soustraction.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET content_count = FLOOR( content_count * ( 1 - DATEDIFF( %s, day ) / 60 ) ), media_count = FLOOR( media_count * ( 1 - DATEDIFF( %s, day ) / 45 ) ), users_count = GREATEST( 1, CAST( users_count AS SIGNED ) - FLOOR( DATEDIFF( %s, day ) / 12 ) ) WHERE network_id = %d AND day < %s',
			Schema::snapshots_table(),
			$today,
			$today,
			$today,
			$network,
			$today
		)
	);
	// Les alertes de l'Innovation Lab et de l'Intranet sont apparues il y a dix jours.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET alert_level = 0, alerts_count = 0 WHERE network_id = %d AND day < %s AND site_id IN (%d, %d)',
			Schema::snapshots_table(),
			$network,
			gmdate( 'Y-m-d', $now - 10 * DAY_IN_SECONDS ),
			$site( 'lab' ),
			$site( 'intranet' )
		)
	);

	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d', Schema::events_table(), $network ) );
	$changes = [
		[ 'careers', 'site_created', '', [ 'name' => 'Careers' ], 26 ],
		[ 'press', 'theme_switched', 'twentytwentythree', [ 'from' => 'twentytwentyfive' ], 21 ],
		[ 'lab', 'plugin_activated', 'hello.php', [], 12 ],
		[ 'lab', 'alert_raised', 'no_users', [], 10 ],
		[ 'intranet', 'alert_raised', 'search_hidden', [], 10 ],
		[ 'events', 'plugin_activated', 'msradar-demo-cpt/msradar-demo-cpt.php', [], 6 ],
		[ 'hr', 'plugin_activated', 'msradar-demo-cpt/msradar-demo-cpt.php', [], 4 ],
		[ 'engineering', 'theme_switched', 'twentytwentyfive', [ 'from' => 'twentytwentyfour' ], 2 ],
		[ 'lab', 'plugin_deactivated', 'hello.php', [], 1 ],
		[ 'marketing', 'alert_resolved', 'no_admin', [], 1 ],
	];
	$rows    = [];
	foreach ( $changes as $change ) {
		$id      = $site( $change[0] );
		$details = get_site( $id );
		$rows[]  = [
			'network_id' => $network,
			'site_id'    => $id,
			'type'       => $change[1],
			'subject'    => 'site_created' === $change[1] && $details ? $details->domain . $details->path : $change[2],
			'meta'       => $change[3],
			'created_at' => gmdate( 'Y-m-d H:i:s', $now - $change[4] * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS ),
		];
	}
	$plugin->events()->insert( $rows );
	WP_CLI::success( sprintf( '31 days of figures and %d changes written.', count( $rows ) ) );
} )();
