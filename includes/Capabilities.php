<?php
namespace MultisiteRadar;

defined( 'ABSPATH' ) || exit;

/**
 * Capacités du plugin, résolues vers des capacités réseau existantes.
 */
final class Capabilities {

	public const VIEW   = 'msradar_view';
	public const MANAGE = 'msradar_manage';

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map' ], 10, 2 );
	}

	/**
	 * @param string[] $caps Capacités primitives requises.
	 * @param string   $cap  Capacité demandée.
	 * @return string[]
	 */
	public static function map( array $caps, string $cap ): array {
		if ( self::VIEW !== $cap && self::MANAGE !== $cap ) {
			return $caps;
		}

		$map = (array) apply_filters(
			'msradar_capability_map',
			[
				self::VIEW   => 'manage_network',
				self::MANAGE => 'manage_network_options',
			]
		);

		return [ isset( $map[ $cap ] ) ? (string) $map[ $cap ] : 'do_not_allow' ];
	}
}
