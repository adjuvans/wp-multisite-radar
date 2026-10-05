<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\AuthorsRepository;
use MultisiteRadar\Storage\UsersRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Comptes de l'installation, nombre de sites, rôles, noms et contenus publiés de chacun, tous réseaux confondus
 * (écart E3 du plan M3). L'e-mail n'est renvoyé que sur demande (with_email, droit manage_network_users vérifié par
 * la route). Les résultats restent 10 minutes en cache objet ; la clé change dès qu'un compte ou une appartenance
 * change (last_changed « users »), qu'un site est créé ou supprimé (« sites »), qu'une analyse relève les auteurs
 * (« msradar_authors »), ou que la liste des super-admins change. La clé dépend aussi de la locale (noms des rôles
 * traduits).
 */
final class UsersQuery {

	public const ORDERBY     = [ 'login', 'display_name', 'email', 'sites_count', 'published', 'registered' ];
	public const MEMBERSHIPS = [ 'none', 'several' ];
	public const CACHE_GROUP = 'msradar';
	public const CACHE_TTL   = 600;

	/**
	 * Sites listés au plus dans la fiche d'un compte.
	 */
	public const PANEL_SITES = 200;

	private UsersRepository $users;
	private AuthorsRepository $authors;

	public function __construct( UsersRepository $users, AuthorsRepository $authors ) {
		$this->users   = $users;
		$this->authors = $authors;
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
			'with_email'  => false,
		];
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args       = array_merge( self::defaults(), $args );
		$with_email = (bool) $args['with_email'];
		$supers     = self::super_admins();
		$orderby    = (string) $args['orderby'];
		if ( ! in_array( $orderby, self::ORDERBY, true ) || ( 'email' === $orderby && ! $with_email ) ) {
			$orderby = 'login';
		}
		$query = [
			'search'     => trim( (string) $args['search'] ),
			'with_email' => $with_email,
			'membership' => in_array( $args['membership'], self::MEMBERSHIPS, true ) ? (string) $args['membership'] : '',
			'logins'     => $args['super_admin'] ? $supers : null,
			'orderby'    => $orderby,
			'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
		];

		// Les noms des rôles sont traduits dans la langue de qui lit la liste : la locale entre dans la clé.
		$key    = 'users:' . md5( (string) wp_json_encode( [ $query, $supers, determine_locale() ] ) ) . ':' . wp_cache_get_last_changed( 'users' ) . ':' . wp_cache_get_last_changed( 'sites' ) . ':' . wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result  = $this->users->query( $query );
		$ids     = array_map( static fn ( array $row ): int => $row['id'], $result['items'] );
		$names   = $this->users->names( $ids );
		$roles   = $this->users->roles( $ids );
		$counted = ! $this->authors->is_empty();
		$totals  = $counted ? $this->authors->totals( $ids ) : [];
		$labels  = self::role_labels();
		$items   = [];
		foreach ( $result['items'] as $row ) {
			$item = [
				'id'             => $row['id'],
				'login'          => $row['login'],
				'display_name'   => $row['display_name'],
				'first_name'     => $names[ $row['id'] ]['first_name'] ?? '',
				'last_name'      => $names[ $row['id'] ]['last_name'] ?? '',
				'roles'          => self::roles_summary( $roles[ $row['id'] ] ?? [], $labels ),
				'published'      => $counted ? ( $totals[ $row['id'] ] ?? 0 ) : null,
				'super_admin'    => in_array( $row['login'], $supers, true ),
				'sites_count'    => $row['sites_count'],
				'registered_gmt' => self::registered( $row['registered'] ),
				'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $row['id'] ),
			];
			if ( $with_email ) {
				$item['email'] = $row['email'];
			}
			$items[] = $item;
		}
		$list = [
			'items' => $items,
			'total' => $result['total'],
		];
		wp_cache_set( $key, $list, self::CACHE_GROUP, self::CACHE_TTL );
		return $list;
	}

	/**
	 * Fiche d'un compte : identité, et ses sites ($limit au plus, par nom) avec son rôle et ses contenus publiés sur
	 * chacun ; null si le compte n'existe pas.
	 *
	 * @param int $limit Sites listés au plus ; sites_total les compte tous.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function get( int $user_id, bool $with_email, int $limit = self::PANEL_SITES ): ?array {
		$user = $this->users->find( $user_id );
		if ( null === $user ) {
			return null;
		}
		$names    = $this->users->names( [ $user_id ] )[ $user_id ];
		$sites    = $this->users->sites_of( $user_id );
		$counted  = ! $this->authors->is_empty();
		$by_site  = $counted ? $this->authors->for_user( $user_id ) : [];
		$analysed = $counted ? $this->authors->analysed_among( array_column( $sites, 'site_id' ) ) : [];
		$labels   = self::role_labels();

		$items = [];
		foreach ( $sites as $site ) {
			$items[] = [
				'id'        => $site['site_id'],
				'name'      => '' !== $site['name'] ? $site['name'] : $site['domain'] . untrailingslashit( $site['path'] ),
				'admin_url' => '' !== $site['siteurl'] ? trailingslashit( $site['siteurl'] ) . 'wp-admin/' : get_admin_url( $site['site_id'] ),
				'roles'     => array_map(
					static fn ( string $role ): array => [
						'role'  => $role,
						'label' => $labels[ $role ] ?? $role,
					],
					$site['roles']
				),
				'published' => isset( $analysed[ $site['site_id'] ] ) ? ( $by_site[ $site['site_id'] ] ?? 0 ) : null,
			];
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$name = strnatcasecmp( $a['name'], $b['name'] );
				return 0 !== $name ? $name : $a['id'] <=> $b['id'];
			}
		);

		$detail = [
			'id'             => $user['id'],
			'login'          => $user['login'],
			'display_name'   => $user['display_name'],
			'first_name'     => $names['first_name'],
			'last_name'      => $names['last_name'],
			'super_admin'    => in_array( $user['login'], self::super_admins(), true ),
			'registered_gmt' => self::registered( $user['registered'] ),
			'edit_url'       => network_admin_url( 'user-edit.php?user_id=' . $user['id'] ),
			'published'      => $counted ? array_sum( $by_site ) : null,
			'sites'          => array_slice( $items, 0, max( 1, $limit ) ),
			'sites_total'    => count( $items ),
		];
		if ( $with_email ) {
			$detail['email'] = $user['email'];
		}
		return $detail;
	}

	/**
	 * @return string[] Identifiants des super-admins.
	 */
	private static function super_admins(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_super_admins() ), static fn ( string $login ): bool => '' !== $login ) );
	}

	private static function registered( string $value ): ?string {
		return '' !== $value && '0000-00-00 00:00:00' !== $value ? mysql_to_rfc3339( $value ) : null;
	}

	/**
	 * Nom traduit de chaque rôle connu du site courant (le site principal, dans l'administration du réseau).
	 *
	 * @return array<string, string>
	 */
	private static function role_labels(): array {
		$labels = [];
		foreach ( wp_roles()->role_names as $role => $name ) {
			$labels[ (string) $role ] = translate_user_role( (string) $name );
		}
		return $labels;
	}

	/**
	 * @param array<string, int>    $counts Rôle => nombre de sites.
	 * @param array<string, string> $labels Rôle => nom traduit.
	 * @return array<int, array{role: string, label: string, sites: int}> Du rôle le plus fréquent au moins fréquent, puis par nom.
	 */
	private static function roles_summary( array $counts, array $labels ): array {
		$roles = [];
		foreach ( $counts as $role => $sites ) {
			$roles[] = [
				'role'  => (string) $role,
				'label' => $labels[ $role ] ?? (string) $role,
				'sites' => (int) $sites,
			];
		}
		usort(
			$roles,
			static function ( array $a, array $b ): int {
				$by_sites = $b['sites'] <=> $a['sites'];
				return 0 !== $by_sites ? $by_sites : strnatcasecmp( $a['label'], $b['label'] );
			}
		);
		return $roles;
	}
}
