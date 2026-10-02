<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\UsersRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Comptes de l'installation et nombre de sites de chacun, tous réseaux confondus (écart E3 du plan M3).
 * Aucune adresse e-mail n'est lue ni renvoyée. Les résultats restent 10 minutes en cache objet ; la clé change dès
 * qu'un compte ou une appartenance change (last_changed « users »), qu'un site est créé ou supprimé (« sites »),
 * ou que la liste des super-admins change.
 */
final class UsersQuery {

	public const ORDERBY     = [ 'login', 'display_name', 'sites_count', 'registered' ];
	public const MEMBERSHIPS = [ 'none', 'several' ];
	public const CACHE_GROUP = 'msradar';
	public const CACHE_TTL   = 600;

	private UsersRepository $users;

	public function __construct( UsersRepository $users ) {
		$this->users = $users;
	}

	public static function defaults(): array {
		return [
			'page'        => 1,
			'per_page'    => 20,
			'search'      => '',
			'membership'  => '',
			'super_admin' => false,
			'orderby'     => 'login',
			'order'       => 'asc',
		];
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args    = array_merge( self::defaults(), $args );
		$supers  = array_values( array_filter( array_map( 'strval', (array) get_super_admins() ), static fn ( string $login ): bool => '' !== $login ) );
		$orderby = (string) $args['orderby'];
		$query   = [
			'search'     => trim( (string) $args['search'] ),
			'membership' => in_array( $args['membership'], self::MEMBERSHIPS, true ) ? (string) $args['membership'] : '',
			'logins'     => $args['super_admin'] ? $supers : null,
			'orderby'    => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'login',
			'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
		];

		$key    = 'users:' . md5( (string) wp_json_encode( [ $query, $supers ] ) ) . ':' . wp_cache_get_last_changed( 'users' ) . ':' . wp_cache_get_last_changed( 'sites' );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->users->query( $query );
		$items  = [];
		foreach ( $result['items'] as $row ) {
			$items[] = [
				'id'             => $row['id'],
				'login'          => $row['login'],
				'display_name'   => $row['display_name'],
				'super_admin'    => in_array( $row['login'], $supers, true ),
				'sites_count'    => $row['sites_count'],
				'registered_gmt' => '' !== $row['registered'] && '0000-00-00 00:00:00' !== $row['registered'] ? mysql_to_rfc3339( $row['registered'] ) : null,
				'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $row['id'] ),
			];
		}
		$list = [
			'items' => $items,
			'total' => $result['total'],
		];
		wp_cache_set( $key, $list, self::CACHE_GROUP, self::CACHE_TTL );
		return $list;
	}
}
