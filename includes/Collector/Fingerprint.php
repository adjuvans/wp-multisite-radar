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

	/**
	 * Empreinte à partir des valeurs d'options telles qu'elles sont stockées, éventuellement corrompues.
	 * Seule normalisation des empreintes : utilisée au chargement du plugin (current()) comme par le collecteur.
	 *
	 * @param mixed $active_plugins   Valeur de l'option active_plugins du site.
	 * @param mixed $sitewide_plugins Valeur de l'option réseau active_sitewide_plugins.
	 * @param mixed $stylesheet       Valeur de l'option stylesheet.
	 * @param mixed $template         Valeur de l'option template.
	 */
	public static function from_raw( $active_plugins, $sitewide_plugins, $stylesheet, $template ): string {
		global $wp_version;
		return self::compute(
			self::plugin_files( $active_plugins ),
			self::network_plugin_files( $sitewide_plugins ),
			is_scalar( $stylesheet ) ? (string) $stylesheet : '',
			is_scalar( $template ) ? (string) $template : '',
			(string) $wp_version,
			MSRADAR_VERSION
		);
	}

	/**
	 * @param mixed $active_plugins Valeur de l'option active_plugins.
	 * @return string[] Fichiers des plugins actifs du site.
	 */
	public static function plugin_files( $active_plugins ): array {
		return is_array( $active_plugins ) ? array_values( array_filter( $active_plugins, 'is_string' ) ) : [];
	}

	/**
	 * @param mixed $sitewide_plugins Valeur de l'option réseau active_sitewide_plugins (fichier => date d'activation).
	 * @return string[] Fichiers des plugins activés sur le réseau.
	 */
	public static function network_plugin_files( $sitewide_plugins ): array {
		return is_array( $sitewide_plugins ) ? array_values( array_filter( array_keys( $sitewide_plugins ), 'is_string' ) ) : [];
	}

	public static function current(): string {
		return self::from_raw(
			get_option( 'active_plugins', [] ),
			get_site_option( 'active_sitewide_plugins', [] ),
			get_option( 'stylesheet', '' ),
			get_option( 'template', '' )
		);
	}
}
