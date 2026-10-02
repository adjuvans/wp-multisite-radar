<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Pied de page des pages du plugin : signature ADJUVANS et licences à gauche, version du plugin à droite.
 * Les autres pages de l'administration gardent le pied de page de WordPress.
 */
final class Footer {

	public const AUTHOR_URL       = 'https://adjuvans.fr';
	public const CONTACT          = 'contact@adjuvans.fr';
	public const LICENSE_URL      = 'https://www.gnu.org/licenses/gpl-3.0.html';
	public const THIRD_PARTY_FILE = 'third-party-licenses.txt';

	private Menu $menu;
	private string $build_dir;
	private string $build_url;

	public function __construct( Menu $menu, string $build_dir, string $build_url ) {
		$this->menu      = $menu;
		$this->build_dir = trailingslashit( $build_dir );
		$this->build_url = trailingslashit( $build_url );
	}

	public function register(): void {
		add_filter( 'admin_footer_text', [ $this, 'credits' ], 20 );
		add_filter( 'update_footer', [ $this, 'version' ], 20 );
	}

	/**
	 * @param mixed $text Texte de gauche du pied de page.
	 * @return mixed
	 */
	public function credits( $text ) {
		if ( ! $this->on_plugin_page() ) {
			return $text;
		}
		$parts = [
			sprintf(
				/* translators: %s: ADJUVANS, linked to its website. */
				esc_html__( 'Multisite Radar by %s', 'multisite-radar' ),
				'<a href="' . esc_url( self::AUTHOR_URL ) . '">ADJUVANS</a>'
			),
			'<a href="' . esc_url( 'mailto:' . self::CONTACT ) . '">' . esc_html( self::CONTACT ) . '</a>',
			sprintf(
				/* translators: %s: name of the license, linked to its text. */
				esc_html__( 'License: %s', 'multisite-radar' ),
				'<a href="' . esc_url( self::LICENSE_URL ) . '">' . esc_html__( 'GPL-3.0 or later', 'multisite-radar' ) . '</a>'
			),
		];
		if ( is_readable( $this->build_dir . self::THIRD_PARTY_FILE ) ) {
			$parts[] = '<a href="' . esc_url( $this->build_url . self::THIRD_PARTY_FILE ) . '">' . esc_html__( 'Third-party licenses', 'multisite-radar' ) . '</a>';
		}
		return implode( ' · ', $parts );
	}

	/**
	 * @param mixed $text Texte de droite du pied de page (version de WordPress).
	 * @return mixed
	 */
	public function version( $text ) {
		return $this->on_plugin_page() ? esc_html( 'Multisite Radar ' . MSRADAR_VERSION ) : $text;
	}

	private function on_plugin_page(): bool {
		$hook_suffix = $GLOBALS['hook_suffix'] ?? null;
		return is_string( $hook_suffix ) && null !== $this->menu->view_for_hook( $hook_suffix );
	}
}
