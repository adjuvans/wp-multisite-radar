<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Empreinte de ce qui détermine les types enregistrés d'un site : plugins, thème, versions.
 */
final class Fingerprint {

	/**
	 * @param string[] $active_plugins  Plugins actifs du site.
	 * @param string[] $network_plugins Plugins activés sur le réseau.
	 */
	public static function compute( array $active_plugins, array $network_plugins, string $stylesheet, string $template, string $wp_version, string $plugin_version ): string {
		$active_plugins  = array_values( array_map( 'strval', $active_plugins ) );
		$network_plugins = array_values( array_map( 'strval', $network_plugins ) );
		sort( $active_plugins );
		sort( $network_plugins );

		return md5( (string) wp_json_encode( [ $active_plugins, $network_plugins, $stylesheet, $template, $wp_version, $plugin_version ] ) );
	}

	public static function current(): string {
		global $wp_version;
		$active  = get_option( 'active_plugins', [] );
		$network = get_site_option( 'active_sitewide_plugins', [] );

		return self::compute(
			is_array( $active ) ? array_filter( $active, 'is_string' ) : [],
			is_array( $network ) ? array_keys( $network ) : [],
			(string) get_option( 'stylesheet', '' ),
			(string) get_option( 'template', '' ),
			(string) $wp_version,
			MSRADAR_VERSION
		);
	}
}
