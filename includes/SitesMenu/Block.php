<?php
namespace MultisiteRadar\SitesMenu;

use WP_Block_Type;
use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bloc dynamique multisite-radar/sites-list : liste des sites publics du réseau, rendue en PHP.
 * Sources dans src/blocks/sites-list, compilées par @wordpress/scripts dans build/blocks/sites-list.
 */
final class Block {

	public const NAME = 'multisite-radar/sites-list';

	private SitesListCache $cache;
	private string $dir;
	private ?WP_Block_Type $type = null;

	public function __construct( SitesListCache $cache, string $dir ) {
		$this->cache = $cache;
		$this->dir   = trailingslashit( $dir );
	}

	/**
	 * À appeler sur init. Rien n'est fait si le build manque ou si le bloc est déjà enregistré.
	 */
	public function register(): bool {
		if ( ! is_readable( $this->dir . 'block.json' ) || WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return false;
		}
		$type = register_block_type( $this->dir, [ 'render_callback' => [ $this, 'render' ] ] );
		if ( ! $type instanceof WP_Block_Type ) {
			return false;
		}
		$this->type = $type;
		foreach ( (array) $type->editor_script_handles as $handle ) {
			wp_set_script_translations( $handle, 'multisite-radar', MSRADAR_DIR . 'languages' );
		}
		add_action( 'enqueue_block_editor_assets', [ $this, 'editor_data' ] );
		return true;
	}

	/**
	 * Sites proposés dans les réglages du bloc : identifiant et nom des sites publics, déjà visibles de tous.
	 */
	public function editor_data(): void {
		if ( null === $this->type ) {
			return;
		}
		$sites = array_map(
			static fn ( array $site ): array => [
				'id'   => (int) $site['id'],
				'name' => Renderer::label( $site ),
			],
			$this->cache->get()
		);
		foreach ( (array) $this->type->editor_script_handles as $handle ) {
			wp_add_inline_script( $handle, 'window.msradarSitesList = ' . wp_json_encode( $sites, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}
	}

	/**
	 * @param mixed $attributes Attributs du bloc (validés par block.json).
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : [];
		$sites      = Renderer::select(
			$this->cache->get(),
			array_map( 'intval', (array) ( $attributes['include'] ?? [] ) ),
			array_map( 'intval', (array) ( $attributes['exclude'] ?? [] ) ),
			(string) ( $attributes['orderBy'] ?? 'name' ),
			(string) ( $attributes['order'] ?? 'asc' )
		);
		if ( [] === $sites ) {
			return '';
		}
		$layout = 'inline' === ( $attributes['layout'] ?? 'list' ) ? 'inline' : 'list';
		return Renderer::render( $sites, 'ul', get_block_wrapper_attributes( [ 'class' => 'msradar-sites msradar-sites--' . $layout ] ) );
	}
}
