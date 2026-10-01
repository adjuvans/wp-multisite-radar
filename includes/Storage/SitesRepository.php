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

	/**
	 * Colonnes lues par les listes : tout sauf data, le JSON détaillé, lu seulement par find() et find_many().
	 */
	private const LIST_COLUMNS = 'site_id, network_id, name, url, siteurl, is_public, is_archived, is_spam, is_deleted, theme_stylesheet, theme_template, users_count, admins_count, content_count, media_count, disk_bytes, disk_is_estimate, db_bytes, autoload_bytes, last_activity_gmt, alert_level, alerts_count, alert_rules, registry_status, dirty, dirty_since, scanned_at';

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
	 * @throws \LogicException Si l'enregistrement vient d'une liste.
	 */
	public function save( SiteRecord $record ): void {
		if ( $record->partial ) {
			throw new \LogicException( 'A partial site record (read from a list) cannot be written back.' );
		}
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

	/**
	 * @throws \LogicException Si l'enregistrement vient d'une liste.
	 */
	public function save_alerts( SiteRecord $record ): void {
		if ( $record->partial ) {
			throw new \LogicException( 'A partial site record (read from a list) cannot be written back.' );
		}
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
	 * @param int|null $network_id Réseau à traiter ; null pour tous.
	 * @return int[] Sites à analyser, les plus anciennement marqués d'abord.
	 */
	public function next_dirty( int $limit, ?int $network_id = null ): array {
		global $wpdb;
		if ( null === $network_id ) {
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY dirty_since ASC, site_id ASC LIMIT %d', Schema::sites_table(), $limit ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 AND network_id = %d ORDER BY dirty_since ASC, site_id ASC LIMIT %d', Schema::sites_table(), $network_id, $limit ) ) );
	}

	/**
	 * @param int|null $network_id Réseau à traiter ; null pour tous.
	 * @return int[]
	 */
	public function dirty_ids( ?int $network_id = null ): array {
		global $wpdb;
		if ( null === $network_id ) {
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 ORDER BY site_id ASC', Schema::sites_table() ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE dirty = 1 AND network_id = %d ORDER BY site_id ASC', Schema::sites_table(), $network_id ) ) );
	}

	/**
	 * Sites encore marqués parmi ceux demandés (analyse ciblée), les plus anciennement marqués d'abord.
	 *
	 * @param int[] $site_ids
	 * @return int[]
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function dirty_among( array $site_ids, int $network_id ): array {
		$ids = self::ids( $site_ids );
		if ( [] === $ids ) {
			return [];
		}
		global $wpdb;
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM %i WHERE dirty = 1 AND network_id = %d AND site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY dirty_since ASC, site_id ASC',
				array_merge( [ Schema::sites_table(), $network_id ], $ids )
			)
		);
		self::check_read();
		return array_map( 'intval', (array) $found );
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
	 * @param int|null $network_id Réseau à parcourir ; null pour tous.
	 * @return int[] Identifiants strictement supérieurs à $after_id, croissants.
	 */
	public function ids_after( int $after_id, int $limit, ?int $network_id = null ): array {
		global $wpdb;
		if ( null === $network_id ) {
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id > %d ORDER BY site_id ASC LIMIT %d', Schema::sites_table(), $after_id, $limit ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT site_id FROM %i WHERE site_id > %d AND network_id = %d ORDER BY site_id ASC LIMIT %d', Schema::sites_table(), $after_id, $network_id, $limit ) ) );
	}

	/**
	 * @param array<int|string> $values
	 * @return int[] Identifiants positifs uniques.
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $values ), static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * @param int[] $site_ids
	 * @return int[] Ceux qui appartiennent au réseau, par ordre croissant.
	 */
	public function ids_in_network( array $site_ids, int $network_id ): array {
		global $wpdb;
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $site_ids ) {
			return [];
		}
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM %i WHERE network_id = %d AND site_id IN (' . implode( ',', array_fill( 0, count( $site_ids ), '%d' ) ) . ') ORDER BY site_id ASC',
				array_merge( [ Schema::sites_table(), $network_id ], $site_ids )
			)
		);
		self::check_read();
		return array_map( 'intval', (array) $found );
	}

	/**
	 * Couples (site, règle) des sites en alerte du réseau, paginés en SQL.
	 *
	 * Une branche UNION ALL par règle demandée, chacune avec la gravité réglée de la règle en constante :
	 * le tri par gravité et le comptage restent en SQL, sans décoder le JSON des sites.
	 *
	 * @param array $args network_id, rules (identifiant => niveau de gravité), search, orderby, order, page, per_page.
	 * @return array{items: array<int, array{site_id: int, rule: string}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function alert_pairs( array $args ): array {
		global $wpdb;
		$rules = (array) $args['rules'];
		if ( [] === $rules ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$table    = Schema::sites_table();
		$search   = (string) $args['search'];
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$branches = [];
		$params   = [];
		foreach ( $rules as $rule => $level ) {
			$branch = 'SELECT site_id, name, %s AS rule, %d AS severity FROM %i WHERE network_id = %d AND alert_rules LIKE %s';
			array_push( $params, (string) $rule, (int) $level, $table, (int) $args['network_id'], '%' . $wpdb->esc_like( ',' . $rule . ',' ) . '%' );
			if ( '' !== $search ) {
				$branch .= ' AND (name LIKE %s OR url LIKE %s)';
				array_push( $params, $like, $like );
			}
			$branches[] = $branch;
		}
		$union     = implode( ' UNION ALL ', $branches );
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$orders    = [
			'rule'     => "rule {$direction}, name ASC, site_id ASC",
			'name'     => "name {$direction}, site_id ASC, rule ASC",
			'severity' => "severity {$direction}, rule ASC, name ASC, site_id ASC",
		];
		$order_by  = $orders[ $args['orderby'] ] ?? $orders['rule'];
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $union ne contient que des fragments fixes et des placeholders ; $order_by vient d'une liste blanche.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ({$union}) AS pairs", $params ) );
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT site_id, rule FROM ({$union}) AS pairs ORDER BY {$order_by} LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, $offset ] ) ),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

		return [
			'items' => array_map(
				static fn ( array $row ): array => [
					'site_id' => (int) $row['site_id'],
					'rule'    => (string) $row['rule'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}

	/**
	 * Une lecture en échec ne doit pas passer pour une liste vide.
	 *
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * @param array $args Arguments déjà normalisés par SitesQuery::list().
	 * @return array{items: SiteRecord[], total: int}
	 * @throws \RuntimeException Si une lecture échoue.
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
			// Comme la règle « inactive » : un site sans aucune date d'activité n'est pas inactif.
			$clauses[] = '(scanned_at IS NOT NULL AND last_activity_gmt IS NOT NULL AND last_activity_gmt < %s)';
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
		$include = array_map( 'intval', (array) ( $args['include'] ?? [] ) );
		if ( [] !== $include ) {
			$clauses[] = 'site_id IN (' . implode( ',', array_fill( 0, count( $include ), '%d' ) ) . ')';
			$params    = array_merge( $params, $include );
		}

		$table    = Schema::sites_table();
		$columns  = self::LIST_COLUMNS;
		$where    = implode( ' AND ', $clauses );
		$column   = self::ORDERBY[ $args['orderby'] ] ?? 'name';
		$order    = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page = (int) $args['per_page'];
		$offset   = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ne contient que des fragments fixes et des placeholders ; $order vaut ASC ou DESC.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $params ) )
		);
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Parameters are spread via array_merge ; placeholders match.
				"SELECT {$columns} FROM %i WHERE {$where} ORDER BY %i {$order}, site_id ASC LIMIT %d OFFSET %d",
				array_merge( [ $table ], $params, [ $column, $per_page, $offset ] )
			),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

		return [
			'items' => array_map( [ SiteRecord::class, 'from_row' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * @param string[] $rule_ids
	 * @return array{total: int, pending: int, with_alerts: int, error: int, warning: int, info: int, rules: array<string, int>}
	 * @throws \RuntimeException Si une lecture échoue.
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
		self::check_read();

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
			self::check_read();
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
