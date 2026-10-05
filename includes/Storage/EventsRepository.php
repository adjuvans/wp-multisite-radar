<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite Radar's own network tables: no WordPress API reads or writes them, and the query services cache what they need.

/**
 * Tout le SQL de la table msradar_events : le journal des changements du réseau (spec §3.1, lot 3).
 */
final class EventsRepository {

	public const TYPES = [ 'site_created', 'site_deleted', 'plugin_activated', 'plugin_deactivated', 'theme_switched', 'alert_raised', 'alert_resolved' ];

	/**
	 * @param array<int, array{network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}> $events
	 * @throws \RuntimeException Si une écriture échoue.
	 */
	public function insert( array $events ): void {
		global $wpdb;
		foreach ( $events as $event ) {
			$result = $wpdb->insert(
				Schema::events_table(),
				[
					'network_id' => (int) $event['network_id'],
					'site_id'    => (int) $event['site_id'],
					'type'       => (string) $event['type'],
					'subject'    => mb_substr( (string) $event['subject'], 0, 191 ),
					'meta'       => [] === $event['meta'] ? null : (string) wp_json_encode( $event['meta'] ),
					'created_at' => (string) $event['created_at'],
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
			if ( false === $result ) {
				throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
			}
		}
	}

	/**
	 * Événements d'un réseau, les plus récents d'abord.
	 *
	 * @param array $args network_id (int), since (date GMT « Y-m-d H:i:s » ou null), types (string[], vide : tous),
	 *                    site_id (int, 0 : tous), page (int ≥ 1), per_page (int ≥ 1).
	 * @return array{items: array<int, array{id: int, network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}>, total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$clauses = [ 'network_id = %d' ];
		$params  = [ (int) $args['network_id'] ];
		if ( null !== $args['since'] ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = (string) $args['since'];
		}
		$types = array_values( array_intersect( array_map( 'strval', (array) $args['types'] ), self::TYPES ) );
		if ( [] !== $types ) {
			$clauses[] = 'type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params    = array_merge( $params, $types );
		}
		if ( (int) $args['site_id'] > 0 ) {
			$clauses[] = 'site_id = %d';
			$params[]  = (int) $args['site_id'];
		}

		$table    = Schema::events_table();
		$where    = implode( ' AND ', $clauses );
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where ne contient que des fragments fixes et des placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $params ) )
		);
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Parameters are spread via array_merge ; placeholders match.
				"SELECT id, network_id, site_id, type, subject, meta, created_at FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( [ $table ], $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'items' => array_map( [ self::class, 'row' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * @return array<string, int> Type => nombre d'événements depuis $since (GMT), types absents omis.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function counts( int $network_id, string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT type, COUNT(*) AS total FROM %i WHERE network_id = %d AND created_at >= %s GROUP BY type ORDER BY type ASC', Schema::events_table(), $network_id, $since ),
			ARRAY_A
		);
		self::check_read();
		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Identité (nom et adresse) de sites qui n'existent plus, relevée sur leurs événements de création et de
	 * suppression : le plus récent qui porte un nom donne le nom et son adresse ; sans nom, l'adresse du plus récent.
	 *
	 * @param int   $network_id Réseau.
	 * @param int[] $site_ids   Sites cherchés.
	 * @return array<int, array{name: string, subject: string}> Par ID de site ; les sites sans événement de ce type sont omis.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function identities( int $network_id, array $site_ids ): array {
		global $wpdb;
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		if ( [] === $site_ids ) {
			return [];
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- La liste ne contient que des placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Parameters are spread via array_merge ; placeholders match.
				"SELECT id, network_id, site_id, type, subject, meta, created_at FROM %i WHERE network_id = %d AND type IN ('site_deleted','site_created') AND site_id IN (" . implode( ',', array_fill( 0, count( $site_ids ), '%d' ) ) . ') ORDER BY created_at DESC, id DESC',
				array_merge( [ Schema::events_table(), $network_id ], $site_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::check_read();

		$identities = [];
		foreach ( (array) $rows as $row ) {
			$event   = self::row( $row );
			$site_id = $event['site_id'];
			$name    = trim( (string) ( $event['meta']['name'] ?? '' ) );
			if ( ! isset( $identities[ $site_id ] ) ) {
				$identities[ $site_id ] = [
					'name'    => $name,
					'subject' => $event['subject'],
				];
			} elseif ( '' === $identities[ $site_id ]['name'] && '' !== $name ) {
				$identities[ $site_id ] = [
					'name'    => $name,
					'subject' => $event['subject'],
				];
			}
		}
		return $identities;
	}

	/**
	 * @return int Nombre d'événements supprimés (antérieurs à $before, GMT).
	 * @throws \RuntimeException Si la suppression échoue.
	 */
	public function purge( int $network_id, string $before ): int {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND created_at < %s', Schema::events_table(), $network_id, $before ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
		return (int) $deleted;
	}

	/**
	 * @param array $row Ligne brute.
	 * @return array{id: int, network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}
	 */
	private static function row( array $row ): array {
		$meta = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : null;
		return [
			'id'         => (int) $row['id'],
			'network_id' => (int) $row['network_id'],
			'site_id'    => (int) $row['site_id'],
			'type'       => (string) $row['type'],
			'subject'    => (string) $row['subject'],
			'meta'       => is_array( $meta ) ? $meta : [],
			'created_at' => (string) $row['created_at'],
		];
	}

	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
