<?php
namespace MultisiteRadar\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite Radar's own network tables: no WordPress API reads or writes them, and the query services cache what they need.

/**
 * SQL des comptes de l'installation (tables globales users, usermeta, blogs) et de leur nombre de sites.
 */
final class UsersRepository {

	/**
	 * Tri autorisé : clé publique => expression SQL.
	 */
	public const ORDERBY = [
		'login'        => 'u.user_login',
		'display_name' => 'u.display_name',
		'sites_count'  => 'COALESCE(c.sites_count, 0)',
		'registered'   => 'u.user_registered',
	];

	/**
	 * Comptes paginés en SQL, avec leur nombre de sites.
	 *
	 * Une appartenance est une clé {base}capabilities (site 1) ou {base}{id}_capabilities, pour un site qui existe
	 * encore dans blogs (la règle de get_blogs_of_user()). Le préfixe ne contient que [A-Za-z0-9_] (WordPress le
	 * vérifie), il peut donc entrer tel quel dans l'expression régulière.
	 *
	 * @param array $args search, membership ('' | none | several), logins (null ou string[]), orderby, order, page, per_page.
	 * @return array{items: array<int, array{id: int, login: string, display_name: string, registered: string, sites_count: int}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$base = $wpdb->base_prefix;
		// COUNT(DISTINCT) : une clé {base}1_capabilities à côté de {base}capabilities ne compte pas le site 1 deux fois.
		$join   = 'INNER JOIN %i AS b ON b.blog_id = (CASE WHEN m.meta_key = %s THEN 1 ELSE CAST(SUBSTRING_INDEX(SUBSTRING(m.meta_key, %d), %s, 1) AS UNSIGNED) END) WHERE m.meta_key LIKE %s AND m.meta_key REGEXP %s';
		$params = [
			$wpdb->blogs,
			$base . 'capabilities',
			strlen( $base ) + 1,
			'_',
			$wpdb->esc_like( $base ) . '%capabilities',
			'^' . $base . '([0-9]+_)?capabilities$',
		];

		$filtered   = '' !== (string) $args['membership'] && in_array( $args['membership'], [ 'none', 'several' ], true );
		$derived    = $filtered || 'sites_count' === $args['orderby'];
		$conditions = [];
		$where      = [];
		if ( '' !== (string) $args['search'] ) {
			$like         = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]      = '(u.user_login LIKE %s OR u.display_name LIKE %s)';
			$conditions[] = $like;
			$conditions[] = $like;
		}
		if ( 'none' === $args['membership'] ) {
			$where[] = 'c.sites_count IS NULL';
		} elseif ( 'several' === $args['membership'] ) {
			$where[] = 'c.sites_count >= 2';
		}
		if ( null !== $args['logins'] ) {
			$logins = array_values( array_map( 'strval', (array) $args['logins'] ) );
			// Une liste vide ne retient personne : jamais tous les comptes.
			$where[]    = [] === $logins ? '1 = 0' : 'u.user_login IN (' . implode( ',', array_fill( 0, count( $logins ), '%s' ) ) . ')';
			$conditions = array_merge( $conditions, $logins );
		}

		$condition = [] === $where ? '1 = 1' : implode( ' AND ', $where );
		$sort      = self::ORDERBY[ $args['orderby'] ] ?? self::ORDERBY['login'];
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $join, $from et $condition ne contiennent que des fragments fixes et des placeholders ; $sort vient d'une liste blanche ; $direction vaut ASC ou DESC.
		if ( $derived ) {
			// Filtre d'appartenance ou tri par nombre de sites : la table dérivée agrège les appartenances de tous les comptes.
			$from   = "%i AS u LEFT JOIN (SELECT m.user_id, COUNT(DISTINCT b.blog_id) AS sites_count FROM %i AS m {$join} GROUP BY m.user_id) AS c ON c.user_id = u.ID";
			$prefix = array_merge( [ $wpdb->users, $wpdb->usermeta ], $params );
			$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", array_merge( $prefix, $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_registered, COALESCE(c.sites_count, 0) AS sites_count FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( $prefix, $conditions, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
			self::check_read();
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i AS u WHERE {$condition}", array_merge( [ $wpdb->users ], $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_registered FROM %i AS u WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( [ $wpdb->users ], $conditions, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
			self::check_read();
			$rows = (array) $rows;
			if ( [] !== $rows ) {
				// Les appartenances ne sont comptées que pour les comptes de la page.
				$ids    = array_map( static fn ( array $row ): int => (int) $row['ID'], $rows );
				$counts = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT m.user_id, COUNT(DISTINCT b.blog_id) AS sites_count FROM %i AS m ' . $join . ' AND m.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY m.user_id',
						array_merge( [ $wpdb->usermeta ], $params, $ids )
					),
					ARRAY_A
				);
				self::check_read();
				$by_user = [];
				foreach ( (array) $counts as $count ) {
					$by_user[ (int) $count['user_id'] ] = (int) $count['sites_count'];
				}
				foreach ( $rows as $i => $row ) {
					$rows[ $i ]['sites_count'] = $by_user[ (int) $row['ID'] ] ?? 0;
				}
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'items' => array_map(
				static fn ( array $row ): array => [
					'id'           => (int) $row['ID'],
					'login'        => (string) $row['user_login'],
					'display_name' => (string) $row['display_name'],
					'registered'   => (string) $row['user_registered'],
					'sites_count'  => (int) $row['sites_count'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}

	/**
	 * @throws \RuntimeException Si la dernière requête a échoué.
	 */
	private static function check_read(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
