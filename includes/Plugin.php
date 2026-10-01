<?php
namespace MultisiteRadar;

use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Conteneur : construit les services à la demande et branche les hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private ?Settings $settings = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'render_multisite_notice' ] );
			return;
		}

		Capabilities::register();
	}

	public function render_multisite_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Multisite Radar requires a WordPress Multisite network. It does nothing on a single site.', 'multisite-radar' )
		);
	}

	public function settings(): Settings {
		return $this->settings ??= new Settings();
	}

	/**
	 * Vide les caches en mémoire des services (utile après une annulation de transaction en test).
	 */
	public function reset_caches(): void {
		if ( null !== $this->settings ) {
			$this->settings->reset_cache();
		}
	}
}
