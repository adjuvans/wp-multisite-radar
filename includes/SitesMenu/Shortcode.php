<?php
namespace MultisiteRadar\SitesMenu;

defined( 'ABSPATH' ) || exit;

/**
 * [msradar_sites wrapper="ul" class="…"] et l'alias 1.x [network_sites_menu].
 */
final class Shortcode {

	public const TAG        = 'msradar_sites';
	public const LEGACY_TAG = 'network_sites_menu';

	private SitesListCache $cache;

	public function __construct( SitesListCache $cache ) {
		$this->cache = $cache;
	}

	public function register( bool $legacy ): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		// Le MU-plugin 1.x encore chargé garde la main sur son shortcode.
		if ( $legacy && ! shortcode_exists( self::LEGACY_TAG ) ) {
			add_shortcode( self::LEGACY_TAG, [ $this, 'render_legacy' ] );
		}
	}

	/**
	 * @param mixed $atts Attributs du shortcode.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'wrapper' => 'ul',
				'class'   => 'msradar-sites',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);
		return $this->markup( (string) $atts['wrapper'], (string) $atts['class'] );
	}

	/**
	 * Alias 1.x : mêmes attributs, classe et ordre (par identifiant) de la 1.x.
	 *
	 * @param mixed $atts Attributs du shortcode.
	 */
	public function render_legacy( $atts ): string {
		$atts = shortcode_atts(
			[
				'wrapper' => 'ul',
				'class'   => 'network-sites-menu',
			],
			is_array( $atts ) ? $atts : [],
			self::LEGACY_TAG
		);
		return $this->markup( (string) $atts['wrapper'], (string) $atts['class'], 'id' );
	}

	public function markup( string $wrapper, string $css_class, string $order_by = 'name' ): string {
		$sites = Renderer::select( $this->cache->get(), [], [], $order_by, 'asc' );
		if ( [] === $sites ) {
			return '';
		}
		return Renderer::render( $sites, $wrapper, 'class="' . esc_attr( $css_class ) . '"' );
	}
}
