<?php
namespace MultisiteRadar\Query;

use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Utilisateurs d'un site, lus à la demande (onglet Utilisateurs de la fiche).
 * Aucune adresse e-mail n'est exposée : identifiant, login, nom affiché, rôles, super-admin, inscription.
 */
final class SiteUsersQuery {

	public const ORDERBY = [ 'login', 'display_name', 'registered' ];

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'role'     => '',
			'orderby'  => 'login',
			'order'    => 'asc',
		];
	}

	/**
	 * @return array{items: array[], total: int}|null Null si le site n'existe pas dans le réseau courant.
	 */
	public function list( int $site_id, array $args ): ?array {
		global $wpdb;
		$site = get_site( $site_id );
		if ( null === $site || get_current_network_id() !== (int) $site->network_id ) {
			return null;
		}

		$args       = array_merge( self::defaults(), $args );
		$orderby    = (string) $args['orderby'];
		$query_args = [
			'blog_id'     => $site_id,
			'number'      => min( 100, max( 1, (int) $args['per_page'] ) ),
			'paged'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'orderby'     => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'login',
			'order'       => 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC',
			'fields'      => [ 'ID', 'user_login', 'display_name', 'user_registered' ],
			'count_total' => true,
		];
		$search     = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = [ 'user_login', 'display_name' ];
		}
		$role = sanitize_key( (string) $args['role'] );
		if ( '' !== $role ) {
			$query_args['role'] = $role;
		}

		$query  = new WP_User_Query( $query_args );
		$users  = (array) $query->get_results();
		$prefix = $wpdb->get_blog_prefix( $site_id );
		$roles  = array_keys( (array) get_blog_option( $site_id, $prefix . 'user_roles', [] ) );
		update_meta_cache( 'user', array_map( static fn ( $user ): int => (int) $user->ID, $users ) );

		$items = [];
		foreach ( $users as $user ) {
			$caps    = get_user_meta( (int) $user->ID, $prefix . 'capabilities', true );
			$granted = array_keys( array_filter( is_array( $caps ) ? $caps : [] ) );
			$items[] = [
				'id'             => (int) $user->ID,
				'login'          => (string) $user->user_login,
				'display_name'   => (string) $user->display_name,
				// Les capacités accordées individuellement ne sont pas des rôles.
				'roles'          => [] !== $roles ? array_values( array_intersect( $granted, $roles ) ) : $granted,
				'super_admin'    => is_super_admin( (int) $user->ID ),
				'registered_gmt' => '' !== (string) $user->user_registered ? mysql_to_rfc3339( (string) $user->user_registered ) : null,
			];
		}

		return [
			'items' => $items,
			'total' => (int) $query->get_total(),
		];
	}
}
