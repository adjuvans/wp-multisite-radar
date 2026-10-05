<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Network-wide aggregation over the users, usermeta and blogs tables (sites per user, membership filters across all sites), which WP_User_Query cannot express without one query per site; read-only, and the query services cache what they need.

/**
 * SQL des comptes de l'installation (tables globales users, usermeta, blogs) : nombre de sites, rôles, noms et
 * contenus publiés (table msradar_site_authors).
 */
final class UsersRepository {

	/**
	 * Tri autorisé : clé publique => expression SQL.
	 */
	public const ORDERBY = [
		'login'        => 'u.user_login',
		'display_name' => 'u.display_name',
		'email'        => 'u.user_email',
		'sites_count'  => 'COALESCE(c.sites_count, 0)',
		'published'    => 'COALESCE(p.published, 0)',
		'registered'   => 'u.user_registered',
	];

	/**
	 * Comptes paginés en SQL, avec leur nombre de sites.
	 *
	 * @param array $args search, with_email (cherche aussi dans l'e-mail), membership ('' | none | several), logins (null ou string[]), orderby, order, page, per_page.
	 * @return array{items: array<int, array{id: int, login: string, display_name: string, email: string, registered: string, sites_count: int}>, total: int}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function query( array $args ): array {
		global $wpdb;
		$membership = self::membership();
		$join       = $membership['join'] . ' WHERE ' . $membership['where'];
		$params     = array_merge( $membership['join_params'], $membership['where_params'] );

		$filtered   = '' !== (string) $args['membership'] && in_array( $args['membership'], [ 'none', 'several' ], true );
		$derived    = $filtered || 'sites_count' === $args['orderby'];
		$conditions = [];
		$where      = [];
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$columns = [ 'u.user_login LIKE %s', 'u.display_name LIKE %s' ];
			$values  = [ $like, $like ];
			if ( ! empty( $args['with_email'] ) ) {
				$columns[] = 'u.user_email LIKE %s';
				$values[]  = $like;
			}
			$columns[]  = "EXISTS (SELECT 1 FROM %i AS n WHERE n.user_id = u.ID AND n.meta_key IN ('first_name', 'last_name') AND n.meta_value LIKE %s)";
			$values[]   = $wpdb->usermeta;
			$values[]   = $like;
			$where[]    = '(' . implode( ' OR ', $columns ) . ')';
			$conditions = array_merge( $conditions, $values );
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

		// Tri par contenus publiés : total de chaque compte sur les sites qui existent encore.
		$published        = 'published' === $args['orderby'];
		$published_join   = $published ? ' LEFT JOIN (SELECT a.user_id, SUM(a.published) AS published FROM %i AS a INNER JOIN %i AS ab ON ab.blog_id = a.site_id WHERE a.user_id > 0 GROUP BY a.user_id) AS p ON p.user_id = u.ID' : '';
		$published_params = $published ? [ Schema::authors_table(), $wpdb->blogs ] : [];

		$condition = [] === $where ? '1 = 1' : implode( ' AND ', $where );
		$sort      = self::ORDERBY[ $args['orderby'] ] ?? self::ORDERBY['login'];
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $join, $from et $condition ne contiennent que des fragments fixes et des placeholders ; $sort vient d'une liste blanche ; $direction vaut ASC ou DESC.
		if ( $derived ) {
			// Filtre d'appartenance ou tri par nombre de sites : la table dérivée agrège les appartenances de tous les comptes.
			$from   = "%i AS u LEFT JOIN (SELECT m.user_id, COUNT(DISTINCT b.blog_id) AS sites_count FROM %i AS m {$join} GROUP BY m.user_id) AS c ON c.user_id = u.ID{$published_join}";
			$prefix = array_merge( [ $wpdb->users, $wpdb->usermeta ], $params, $published_params );
			$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", array_merge( $prefix, $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_email, u.user_registered, COALESCE(c.sites_count, 0) AS sites_count FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( $prefix, $conditions, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
			self::check_read();
		} else {
			$from   = "%i AS u{$published_join}";
			$prefix = array_merge( [ $wpdb->users ], $published_params );
			$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", array_merge( $prefix, $conditions ) ) );
			self::check_read();
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.display_name, u.user_email, u.user_registered FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
					array_merge( $prefix, $conditions, [ $per_page, $offset ] )
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
					'email'        => (string) $row['user_email'],
					'registered'   => (string) $row['user_registered'],
					'sites_count'  => (int) $row['sites_count'],
				],
				(array) $rows
			),
			'total' => $total,
		];
	}

	/**
	 * Un compte, ou null s'il n'existe pas.
	 *
	 * @return array{id: int, login: string, display_name: string, email: string, registered: string}|null
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ID, user_login, display_name, user_email, user_registered FROM %i WHERE ID = %d', $wpdb->users, $user_id ),
			ARRAY_A
		);
		self::check_read();
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'id'           => (int) $row['ID'],
			'login'        => (string) $row['user_login'],
			'display_name' => (string) $row['display_name'],
			'email'        => (string) $row['user_email'],
			'registered'   => (string) $row['user_registered'],
		];
	}

	/**
	 * Prénom et nom des comptes donnés (chaînes vides par défaut).
	 *
	 * @param int[] $ids
	 * @return array<int, array{first_name: string, last_name: string}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function names( array $ids ): array {
		global $wpdb;
		$ids   = self::ids( $ids );
		$names = array_fill_keys(
			$ids,
			[
				'first_name' => '',
				'last_name'  => '',
			]
		);
		if ( [] === $ids ) {
			return $names;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value FROM %i WHERE meta_key IN ('first_name', 'last_name') AND user_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ $wpdb->usermeta ], $ids )
			),
			ARRAY_A
		);
		self::check_read();
		foreach ( (array) $rows as $row ) {
			$names[ (int) $row['user_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		return $names;
	}

	/**
	 * Rôles des comptes donnés : identifiant du rôle => nombre de sites (qui existent encore) où le compte l'a.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, int>>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function roles( array $ids ): array {
		$roles = [];
		foreach ( $this->memberships_of( $ids ) as $user_id => $sites ) {
			foreach ( $sites as $site ) {
				foreach ( $site['roles'] as $role ) {
					$roles[ $user_id ][ $role ] = ( $roles[ $user_id ][ $role ] ?? 0 ) + 1;
				}
			}
		}
		return $roles;
	}

	/**
	 * Sites d'un compte, par identifiant croissant, avec ses rôles, le nom et l'adresse relevés par l'analyse
	 * (vides pour un site jamais analysé), le domaine et le chemin.
	 *
	 * @return array<int, array{site_id: int, name: string, siteurl: string, domain: string, path: string, roles: string[]}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function sites_of( int $user_id ): array {
		return array_values( $this->memberships_of( [ $user_id ] )[ $user_id ] ?? [] );
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array<int, array{site_id: int, name: string, siteurl: string, domain: string, path: string, roles: string[]}>> Compte => site => appartenance.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	private function memberships_of( array $ids ): array {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( [] === $ids ) {
			return [];
		}
		$membership = self::membership();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The joined fragments are fixed strings with placeholders (self::membership()); the IN list holds one %d per id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.user_id, b.blog_id, b.domain, b.path, s.name, s.siteurl, m.meta_value FROM %i AS m ' . $membership['join'] . ' LEFT JOIN %i AS s ON s.site_id = b.blog_id WHERE ' . $membership['where'] . ' AND m.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY m.user_id ASC, b.blog_id ASC',
				array_merge( [ $wpdb->usermeta ], $membership['join_params'], [ Schema::sites_table() ], $membership['where_params'], $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		self::check_read();

		$out = [];
		foreach ( (array) $rows as $row ) {
			$user = (int) $row['user_id'];
			$site = (int) $row['blog_id'];
			// {base}capabilities et {base}1_capabilities désignent tous deux le site 1 : une seule fois.
			if ( isset( $out[ $user ][ $site ] ) ) {
				continue;
			}
			$out[ $user ][ $site ] = [
				'site_id' => $site,
				'name'    => (string) ( $row['name'] ?? '' ),
				'siteurl' => (string) ( $row['siteurl'] ?? '' ),
				'domain'  => (string) $row['domain'],
				'path'    => (string) $row['path'],
				'roles'   => self::role_slugs( $row['meta_value'] ),
			];
		}
		return $out;
	}

	/**
	 * Jointure d'une ligne usermeta {base}capabilities (site 1) ou {base}{id}_capabilities avec un site qui existe
	 * encore dans blogs (la règle de get_blogs_of_user()). Le préfixe ne contient que [A-Za-z0-9_] (WordPress le
	 * vérifie), il peut donc entrer tel quel dans l'expression régulière.
	 *
	 * @return array{join: string, where: string, join_params: array, where_params: array}
	 */
	private static function membership(): array {
		global $wpdb;
		$base = $wpdb->base_prefix;
		return [
			'join'         => 'INNER JOIN %i AS b ON b.blog_id = (CASE WHEN m.meta_key = %s THEN 1 ELSE CAST(SUBSTRING_INDEX(SUBSTRING(m.meta_key, %d), %s, 1) AS UNSIGNED) END)',
			'where'        => 'm.meta_key LIKE %s AND m.meta_key REGEXP %s',
			'join_params'  => [ $wpdb->blogs, $base . 'capabilities', strlen( $base ) + 1, '_' ],
			'where_params' => [ $wpdb->esc_like( $base ) . '%capabilities', '^' . $base . '([0-9]+_)?capabilities$' ],
		];
	}

	/**
	 * Rôles d'une valeur {base}…capabilities : les clés accordées (WordPress y range les rôles du compte).
	 *
	 * @param mixed $value Valeur brute de usermeta.
	 * @return string[]
	 */
	private static function role_slugs( $value ): array {
		$caps = maybe_unserialize( (string) $value );
		if ( ! is_array( $caps ) ) {
			return [];
		}
		$roles = [];
		foreach ( $caps as $role => $granted ) {
			if ( $granted && is_string( $role ) && '' !== $role ) {
				$roles[] = $role;
			}
		}
		return $roles;
	}

	/**
	 * @param int[] $ids
	 * @return int[] Identifiants positifs, sans doublon.
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
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
