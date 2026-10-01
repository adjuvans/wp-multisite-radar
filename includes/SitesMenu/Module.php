<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Module optionnel « menu des sites » (réglage sites_menu.enabled).
 * L'invalidation du cache est toujours branchée (elle ne fait que supprimer un transient). Les shortcodes, et les alias
 * 1.x si une installation 1.x a été migrée, existent toujours : ils n'affichent rien tant que le module est désactivé,
 * comme la 1.x quand son menu l'était. Le reste (metabox, bloc) n'est branché que si le module est actif.
 */
final class Module {

	/**
	 * Après le MU-plugin 1.x encore chargé, qui déclare sa fonction et son shortcode à plugins_loaded (priorité 10).
	 */
	public const PUBLIC_API_PRIORITY = 20;

	private Settings $settings;
	private SitesListCache $cache;
	private ?Shortcode $shortcode = null;
	private ?NavMenu $nav_menu    = null;
	private ?Block $block         = null;

	public function __construct( Settings $settings, SitesListCache $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	public function register(): void {
		$this->cache->register();
		$this->nav_menu()->register_items();
		// Chargé aussi tôt qu'en 1.x : un thème peut appeler rdc_network_sites_menu() dès son functions.php.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->register_public_api();
		} else {
			add_action( 'plugins_loaded', [ $this, 'register_public_api' ], self::PUBLIC_API_PRIORITY );
		}
		add_action( 'init', [ $this, 'init' ] );
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'sites_menu.enabled', false );
	}

	public function legacy(): bool {
		return false !== get_site_option( LegacyMigration::ALIASES, false );
	}

	/**
	 * Shortcodes et fonction publique 1.x, indépendants de l'activation du module.
	 */
	public function register_public_api(): void {
		$legacy = $this->legacy();
		$this->shortcode()->register( $legacy );
		if ( $legacy ) {
			require_once __DIR__ . '/legacy-functions.php';
		}
	}

	public function init(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$this->nav_menu()->register_editor();
		$this->block()->register();
	}

	public function shortcode(): Shortcode {
		return $this->shortcode ??= new Shortcode( $this->cache, \Closure::fromCallable( [ $this, 'enabled' ] ) );
	}

	public function nav_menu(): NavMenu {
		return $this->nav_menu ??= new NavMenu( $this->cache );
	}

	public function block(): Block {
		return $this->block ??= new Block( $this->cache, MSRADAR_DIR . 'build/blocks/sites-list/' );
	}
}
