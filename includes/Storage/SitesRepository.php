<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le SQL de la table msradar_sites.
 */
final class SitesRepository {

	/**
	 * Tri autorisé : clé publique => colonne.
	 */
	public const ORDERBY = [
		'id'            => 'site_id',
		'name'          => 'name',
		'last_activity' => 'last_activity_gmt',
		'users_count'   => 'users_count',
		'content_count' => 'content_count',
		'media_count'   => 'media_count',
		'disk_bytes'    => 'disk_bytes',
		'db_bytes'      => 'db_bytes',
		'alert_level'   => 'alert_level',
		'scanned_at'    => 'scanned_at',
	];

	private const STATUS_CLAUSES = [
		'public'   => '(is_public = 1 AND is_archived = 0 AND is_spam = 0 AND is_deleted = 0)',
		'private'  => 'is_public = 0',
		'archived' => 'is_archived = 1',
		'spam'     => 'is_spam = 1',
		'deleted'  => 'is_deleted = 1',
	];

	public function find( int $site_id ): ?SiteRecord {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d', Schema::sites_table(), $site_id ), ARRAY_A );
		return is_array( $row ) ? SiteRecord::from_row( $row ) : null;
	}

	/**
	 * @param int[] $site_ids
	 * @return array<int, SiteRecord> Indexés par site_id, dans l'ordre croissant.
	 */
	public function find_many( array $site_ids ): array {
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return [];
		}
		global $wpdb;
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY site_id ASC',
				array_merge( [ Schema::sites_table() ], $ids )
			),
			ARRAY_A
		);
		$records = [];
		foreach ( (array) $rows as $row ) {
			$record                      = SiteRecord::from_row( $row );
			$records[ $record->site_id ] = $record;
		}
		return $records;
	}

	public function exists( int $site_id ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id = %d', Schema::sites_table(), $site_id ) );
	}

	/**
	 * Insère ou met à jour une ligne. Sur une ligne existante, dirty et dirty_since ne sont jamais écrasés :
	 * un marquage posé pendant l'analyse du site survit à l'enregistrement du résultat.
	 *
	 * @throws \RuntimeException Si l'écriture échoue.
	 */
	public function save( SiteRecord $record ): void {
		global $wpdb;
		$row = $record->to_row();
		if ( $this->exists( $record->site_id ) ) {
			unset( $row['site_id'], $row['dirty'], $row['dirty_since'] );
			$result = $wpdb->update( Schema::sites_table(), $row, [ 'site_id' => $record->site_id ] );
		} else {
			$result = $wpdb->insert( Schema::sites_table(), $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}

	public function save_alerts( SiteRecord $record ): void {
		global $wpdb;
		$wpdb->update(
			Schema::sites_table(),
			[
				'alert_level'  => $record->alert_level,
				'alerts_count' => $record->alerts_count,
				'alert_rules'  => $record->alert_rules,
				'data'         => wp_json_encode( $record->data ),
			],
			[ 'site_id' => $record->site_id ]
		);
	}

	public function delete( int $site_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::sites_table(), [ 'site_id' => $site_id ], [ '%d' ] );
	}

	public function insert_pending( int $site_id, int $network_id, string $url ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (site_id, network_id, url, dirty, dirty_since) VALUES (%d, %d, %s, 1, %s)',
				Schema::sites_table(),
				$site_id,
				$network_id,
				$url,
				self::now()
			)
		);
	}

	/**
	 * Insère une ligne « en attente » pour chaque site du réseau qui n'en a pas encore.
	 *
	 * @return int Nombre de lignes insérées.
	 */
	public function seed_from_blogs( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (site_id, network_id, url, dirty, dirty_since) SELECT blog_id, site_id, CONCAT(domain, path), 1, %s FROM %i WHERE site_id = %d',
				Schema::sites_table(),
				self::now(),
				$wpdb->blogs,
				$network_id
			)
		);
	}

	public function delete_orphans(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE s FROM %i s LEFT JOIN %i b ON b.blog_id = s.site_id WHERE b.blog_id IS NULL', Schema::sites_table(), $wpdb->blogs )
		);
	}

	/**
	 * @param int[] $site_ids
	 */
	public function mark_dirty( array $site_ids ): int {
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET dirty = 1, dirty_since = COALESCE(dirty_since, %s) WHERE site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ Schema::sites_table(), self::now() ], $ids )
			)
		);
	}

	public function mark_all_dirty( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET dirty = 1, dirty_since = COALESCE(dirty_since, %s) WHERE network_id = %d', Schema::sites_table(), self::now(), $network_id )
		);
	}

	public function clear_dirty( int $site_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty = 0, dirty_since = NULL WHERE site_id = %d', Schema::sites_table(), $site_id ) );
	}

	/**
	 * @return int[] Sites à analyser, les plus anciennement marqués d'abord.
	 */
	public function next_dirty( int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY dirty_since ASC, site_id ASC LIMIT %d', Schema::sites_table(), $limit ) )
		);
	}

	/**
	 * @return int[]
	 */
	public function dirty_ids(): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY site_id ASC', Schema::sites_table() ) )
		);
	}

	public function count_dirty( ?int $network_id = null ): int {
		global $wpdb;
		if ( null === $network_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE dirty = 1', Schema::sites_table() ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE dirty = 1 AND network_id = %d', Schema::sites_table(), $network_id ) );
	}

	public function count_all( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE network_id = %d', Schema::sites_table(), $network_id ) );
	}

	public function count_pending( int $network_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE network_id = %d AND scanned_at IS NULL', Schema::sites_table(), $network_id ) );
	}

	public function update_last_activity( int $site_id, string $gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_activity_gmt = %s WHERE site_id = %d AND (last_activity_gmt IS NULL OR last_activity_gmt < %s)',
				Schema::sites_table(),
				$gmt,
				$site_id,
				$gmt
			)
		);
	}

	/**
	 * @return int[] Identifiants strictement supérieurs à $after_id, croissants.
	 */
	public function ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id > %d ORDER BY site_id ASC LIMIT %d', Schema::sites_table(), $after_id, $limit ) )
		);
	}

	/**
	 * @param array<int|string> $values
	 * @return int[] Identifiants positifs uniques.
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $values ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * @param array $args Arguments déjà normalisés par SitesQuery::list().
	 * @return array{items: SiteRecord[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$clauses = [ 'network_id = %d' ];
		$params  = [ (int) $args['network_id'] ];

		if ( '' !== $args['search'] ) {
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '(name LIKE %s OR url LIKE %s)';
			array_push( $params, $like, $like );
		}
		if ( [] !== $args['alert_level'] ) {
			$clauses[] = 'alert_level IN (' . implode( ',', array_fill( 0, count( $args['alert_level'] ), '%d' ) ) . ')';
			$params    = array_merge( $params, array_map( 'intval', $args['alert_level'] ) );
		}
		$status_parts = array_values( array_intersect_key( self::STATUS_CLAUSES, array_flip( $args['status'] ) ) );
		if ( [] !== $status_parts ) {
			$clauses[] = '(' . implode( ' OR ', $status_parts ) . ')';
		}
		if ( '' !== $args['theme'] ) {
			$clauses[] = '(theme_stylesheet = %s OR theme_template = %s)';
			array_push( $params, $args['theme'], $args['theme'] );
		}
		if ( '' !== $args['plugin'] ) {
			$clauses[] = "site_id IN (SELECT site_id FROM %i WHERE type = 'plugin' AND slug = %s)";
			array_push( $params, Schema::extensions_table(), $args['plugin'] );
		}
		if ( true === $args['has_users'] ) {
			$clauses[] = 'users_count > 0';
		} elseif ( false === $args['has_users'] ) {
			$clauses[] = '(users_count = 0 AND scanned_at IS NOT NULL)';
		}
		if ( null !== $args['inactive_since'] ) {
			$clauses[] = '(scanned_at IS NOT NULL AND (last_activity_gmt IS NULL OR last_activity_gmt < %s))';
			$params[]  = $args['inactive_since'];
		}
		if ( [] !== $args['registry_status'] ) {
			$clauses[] = 'registry_status IN (' . implode( ',', array_fill( 0, count( $args['registry_status'] ), '%s' ) ) . ')';
			$params    = array_merge( $params, $args['registry_status'] );
		}
		if ( '' !== $args['rule'] ) {
			$clauses[] = 'alert_rules LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( ',' . $args['rule'] . ',' ) . '%';
		}

		$table    = Schema::sites_table();
		$where    = implode( ' AND ', $clauses );
		$column   = self::ORDERBY[ $args['orderby'] ] ?? 'name';
		$order    = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page = (int) $args['per_page'];
		$offset   = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ne contient que des fragments fixes et des placeholders ; $order vaut ASC ou DESC.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $params ) )
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Parameters are spread via array_merge ; placeholders match.
				"SELECT * FROM %i WHERE {$where} ORDER BY %i {$order}, site_id ASC LIMIT %d OFFSET %d",
				array_merge( [ $table ], $params, [ $column, $per_page, $offset ] )
			),
			ARRAY_A
		);
		// phpcs:enable

		return [
			'items' => array_map( [ SiteRecord::class, 'from_row' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * @param string[] $rule_ids
	 * @return array{total: int, pending: int, with_alerts: int, error: int, warning: int, info: int, rules: array<string, int>}
	 */
	public function alert_counts( int $network_id, array $rule_ids ): array {
		global $wpdb;
		$table = Schema::sites_table();
		$row   = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(scanned_at IS NULL) AS pending, SUM(alert_level > 0) AS with_alerts, SUM(alert_level = 3) AS error, SUM(alert_level = 2) AS warning, SUM(alert_level = 1) AS info FROM %i WHERE network_id = %d',
				$table,
				$network_id
			),
			ARRAY_A
		);

		$rules = [];
		foreach ( $rule_ids as $rule_id ) {
			$rules[ $rule_id ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE network_id = %d AND alert_rules LIKE %s',
					$table,
					$network_id,
					'%' . $wpdb->esc_like( ',' . $rule_id . ',' ) . '%'
				)
			);
		}

		return [
			'total'       => (int) ( $row['total'] ?? 0 ),
			'pending'     => (int) ( $row['pending'] ?? 0 ),
			'with_alerts' => (int) ( $row['with_alerts'] ?? 0 ),
			'error'       => (int) ( $row['error'] ?? 0 ),
			'warning'     => (int) ( $row['warning'] ?? 0 ),
			'info'        => (int) ( $row['info'] ?? 0 ),
			'rules'       => $rules,
		];
	}
}
