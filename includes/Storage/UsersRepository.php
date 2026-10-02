<?php
namespace MultisiteRadar\Storage;

defined( 'ABSPATH' ) || exit;

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
		$base        = $wpdb->base_prefix;
		$memberships = 'SELECT m.user_id, COUNT(*) AS sites_count FROM %i AS m INNER JOIN %i AS b ON b.blog_id = (CASE WHEN m.meta_key = %s THEN 1 ELSE CAST(SUBSTRING_INDEX(SUBSTRING(m.meta_key, %d), %s, 1) AS UNSIGNED) END) WHERE m.meta_key LIKE %s AND m.meta_key REGEXP %s GROUP BY m.user_id';
		$params      = [
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->blogs,
			$base . 'capabilities',
			strlen( $base ) + 1,
			'_',
			$wpdb->esc_like( $base ) . '%capabilities',
			'^' . $base . '([0-9]+_)?capabilities$',
		];

		$where = [];
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(u.user_login LIKE %s OR u.display_name LIKE %s)';
			array_push( $params, $like, $like );
		}
		if ( 'none' === $args['membership'] ) {
			$where[] = 'c.sites_count IS NULL';
		} elseif ( 'several' === $args['membership'] ) {
			$where[] = 'c.sites_count >= 2';
		}
		if ( null !== $args['logins'] ) {
			$logins = array_values( array_map( 'strval', (array) $args['logins'] ) );
			// Une liste vide ne retient personne : jamais tous les comptes.
			$where[] = [] === $logins ? '1 = 0' : 'u.user_login IN (' . implode( ',', array_fill( 0, count( $logins ), '%s' ) ) . ')';
			$params  = array_merge( $params, $logins );
		}

		$from      = "%i AS u LEFT JOIN ({$memberships}) AS c ON c.user_id = u.ID";
		$condition = [] === $where ? '1 = 1' : implode( ' AND ', $where );
		$sort      = self::ORDERBY[ $args['orderby'] ] ?? self::ORDERBY['login'];
		$direction = 'desc' === $args['order'] ? 'DESC' : 'ASC';
		$per_page  = (int) $args['per_page'];
		$offset    = ( (int) $args['page'] - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $from et $condition ne contiennent que des fragments fixes et des placeholders ; $sort vient d'une liste blanche ; $direction vaut ASC ou DESC.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$condition}", $params ) );
		self::check_read();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.display_name, u.user_registered, COALESCE(c.sites_count, 0) AS sites_count FROM {$from} WHERE {$condition} ORDER BY {$sort} {$direction}, u.ID ASC LIMIT %d OFFSET %d",
				array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		self::check_read();
		// phpcs:enable

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
