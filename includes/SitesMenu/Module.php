<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Module optionnel « menu des sites » (réglage sites_menu.enabled).
 * L'invalidation du cache est toujours branchée (elle ne fait que supprimer un transient) ; le reste ne l'est que si
 * le module est actif. Les alias 1.x ne le sont que si une installation 1.x a été migrée.
 */
final class Module {

	private Settings $settings;
	private SitesListCache $cache;
	private ?Shortcode $shortcode = null;

	public function __construct( Settings $settings, SitesListCache $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	public function register(): void {
		$this->cache->register();
		add_action( 'init', [ $this, 'init' ] );
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'sites_menu.enabled', false );
	}

	public function legacy(): bool {
		return false !== get_site_option( LegacyMigration::ALIASES, false );
	}

	public function init(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$legacy = $this->legacy();
		$this->shortcode()->register( $legacy );
		if ( $legacy ) {
			require_once __DIR__ . '/legacy-functions.php';
		}
	}

	public function shortcode(): Shortcode {
		return $this->shortcode ??= new Shortcode( $this->cache );
	}
}
