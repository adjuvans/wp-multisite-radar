<?php
namespace MultisiteRadar\SitesMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Choix, tri et rendu HTML de la liste des sites (shortcode, alias 1.x, bloc).
 */
final class Renderer {

	public const WRAPPERS = [ 'ul', 'ol' ];
	public const ORDER_BY = [ 'name', 'id', 'registered' ];

	/**
	 * @param array[] $sites    Liste de SitesListCache::get().
	 * @param int[]   $only     Si non vide, seulement ces sites.
	 * @param int[]   $exclude  Sites à retirer.
	 * @param string  $order_by name (sans casse ni accents), id ou registered.
	 * @param string  $order    asc ou desc.
	 * @return array[]
	 */
	public static function select( array $sites, array $only, array $exclude, string $order_by, string $order ): array {
		$only     = array_map( 'intval', $only );
		$exclude  = array_map( 'intval', $exclude );
		$order_by = in_array( $order_by, self::ORDER_BY, true ) ? $order_by : 'name';
		$sites    = array_values(
			array_filter(
				$sites,
				static function ( array $site ) use ( $only, $exclude ): bool {
					$id = (int) $site['id'];
					return ( [] === $only || in_array( $id, $only, true ) ) && ! in_array( $id, $exclude, true );
				}
			)
		);
		usort(
			$sites,
			static function ( array $a, array $b ) use ( $order_by ): int {
				if ( 'id' === $order_by ) {
					return (int) $a['id'] <=> (int) $b['id'];
				}
				if ( 'registered' === $order_by ) {
					return [ (string) $a['registered'], (int) $a['id'] ] <=> [ (string) $b['registered'], (int) $b['id'] ];
				}
				return [ self::sort_key( self::label( $a ) ), (int) $a['id'] ] <=> [ self::sort_key( self::label( $b ) ), (int) $b['id'] ];
			}
		);
		return 'desc' === $order ? array_reverse( $sites ) : $sites;
	}

	public static function label( array $site ): string {
		$name = trim( (string) $site['name'] );
		if ( '' !== $name ) {
			return $name;
		}
		/* translators: %d: site ID. */
		return sprintf( __( 'Site #%d', 'multisite-radar' ), (int) $site['id'] );
	}

	/**
	 * @param array[] $sites      Sites déjà choisis et triés.
	 * @param string  $wrapper    ul ou ol ; toute autre valeur donne ul.
	 * @param string  $attributes Attributs HTML du conteneur, déjà échappés.
	 */
	public static function render( array $sites, string $wrapper, string $attributes ): string {
		$tag     = in_array( $wrapper, self::WRAPPERS, true ) ? $wrapper : 'ul';
		$current = get_current_blog_id();
		$items   = '';
		foreach ( $sites as $site ) {
			$is_current = $current === (int) $site['id'];
			$items     .= sprintf(
				'<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				esc_attr( 'msradar-sites__item' . ( $is_current ? ' is-current' : '' ) ),
				esc_url( (string) $site['url'] ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( self::label( $site ) )
			);
		}
		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, $attributes, $items );
	}

	private static function sort_key( string $label ): string {
		return remove_accents( function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label ) );
	}
}
