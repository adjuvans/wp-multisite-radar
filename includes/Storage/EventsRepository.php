<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ne contient que des fragments fixes et des placeholders.
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
		// phpcs:enable

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
