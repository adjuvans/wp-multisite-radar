<?php
/**
 * Fonction publique de la 1.x, chargée seulement si une installation 1.x a été migrée (thèmes qui l'appellent).
 *
 * @package MultisiteRadar
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rdc_network_sites_menu' ) ) {
	/**
	 * Affiche la liste des sites du réseau (API 1.x).
	 *
	 * @param string $wrapper ul ou ol.
	 * @param string $class   Classe CSS de la liste.
	 */
	function rdc_network_sites_menu( $wrapper = 'ul', $class = 'network-sites-menu' ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- 1.x public API kept for migrated sites.
		echo MultisiteRadar\Plugin::instance()->sites_menu()->shortcode()->markup( (string) $wrapper, (string) $class, 'id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Renderer::render().
	}
}
