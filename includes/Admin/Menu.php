<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Menu « Multisite Radar » de l'administration réseau : une page par vue, chacune avec le conteneur de l'application.
 */
final class Menu {

	/**
	 * Icône du menu : le radar de l'icône du plugin (bin/wporg-assets/menu-icon.svg), en une couleur que l'administration
	 * remplace par celle du jeu de couleurs de l'utilisateur.
	 */
	public const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTEwIDFhOSA5IDAgMSAxIDAgMTggOSA5IDAgMCAxIDAtMTh6bTAgMS43NWE3LjI1IDcuMjUgMCAxIDAgMCAxNC41IDcuMjUgNy4yNSAwIDAgMCAwLTE0LjV6Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAxMFYyLjc1YTcuMjUgNy4yNSAwIDAgMSA2LjI4IDMuNjN6Ii8+PGNpcmNsZSBmaWxsPSJibGFjayIgY3g9IjEwIiBjeT0iMTAiIHI9IjEuNzUiLz48Y2lyY2xlIGZpbGw9ImJsYWNrIiBjeD0iNiIgY3k9IjEzIiByPSIxLjI1Ii8+PGNpcmNsZSBmaWxsPSJibGFjayIgY3g9IjEzLjUiIGN5PSIxMy41IiByPSIxLjI1Ii8+PC9zdmc+';

	public const PAGES = [
		'overview' => 'multisite-radar',
		'sites'    => 'multisite-radar-sites',
		'plugins'  => 'multisite-radar-plugins',
		'themes'   => 'multisite-radar-themes',
		'users'    => 'multisite-radar-users',
		'alerts'   => 'multisite-radar-alerts',
		'reports'  => 'multisite-radar-reports',
		'settings' => 'multisite-radar-settings',
	];

	/**
	 * @var array<string, string> Suffixe de hook de page => vue.
	 */
	private array $hooks = [];

	public function register(): void {
		add_action( 'network_admin_menu', [ $this, 'add_pages' ] );
	}

	/**
	 * @return array<string, string>
	 */
	public static function titles(): array {
		return [
			'overview' => __( 'Overview', 'multisite-radar' ),
			'sites'    => __( 'Sites', 'multisite-radar' ),
			'plugins'  => __( 'Plugins', 'multisite-radar' ),
			'themes'   => __( 'Themes', 'multisite-radar' ),
			'users'    => __( 'Users', 'multisite-radar' ),
			'alerts'   => __( 'Alerts', 'multisite-radar' ),
			'reports'  => __( 'Reports', 'multisite-radar' ),
			'settings' => __( 'Settings', 'multisite-radar' ),
		];
	}

	public function add_pages(): void {
		$parent = self::PAGES['overview'];
		add_menu_page( __( 'Multisite Radar', 'multisite-radar' ), __( 'Multisite Radar', 'multisite-radar' ), Capabilities::VIEW, $parent, [ $this, 'render' ], self::ICON, 30 );
		foreach ( self::titles() as $view => $title ) {
			$hook = add_submenu_page( $parent, $title, $title, 'settings' === $view ? Capabilities::MANAGE : Capabilities::VIEW, self::PAGES[ $view ], [ $this, 'render' ] );
			if ( false !== $hook ) {
				$this->hooks[ (string) $hook ] = $view;
			}
		}
	}

	public function view_for_hook( string $hook_suffix ): ?string {
		return $this->hooks[ $hook_suffix ] ?? null;
	}

	public static function url( string $view, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGES[ $view ] ?? self::PAGES['overview'] ], $args ), network_admin_url( 'admin.php' ) );
	}

	public static function current_view(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$view = array_search( $page, self::PAGES, true );
		return false === $view ? 'overview' : (string) $view;
	}

	public function render(): void {
		$view  = self::current_view();
		$title = 'overview' === $view ? __( 'Multisite Radar', 'multisite-radar' ) : self::titles()[ $view ];
		printf(
			'<div class="wrap msradar-wrap"><h1 class="wp-heading-inline">%1$s</h1>%2$s<hr class="wp-header-end"><div id="msradar-app" class="msradar-app" data-view="%3$s"></div><noscript><div class="notice notice-error"><p>%4$s</p></div></noscript></div>',
			esc_html( $title ),
			self::version_badge( MSRADAR_VERSION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by version_badge().
			esc_attr( $view ),
			esc_html__( 'Multisite Radar needs JavaScript to display this page.', 'multisite-radar' )
		);
	}

	/**
	 * Pastille de version affichée à côté du titre ; une version préliminaire (« -beta.1 », « -rc.2 »…) le dit en clair.
	 */
	public static function version_badge( string $version ): string {
		if ( false === strpos( $version, '-' ) ) {
			return sprintf( '<span class="msradar-version">%s</span>', esc_html( $version ) );
		}
		return sprintf(
			'<span class="msradar-version is-prerelease">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: version number, such as 2.0.0-rc.2. */
					__( '%s · pre-release', 'multisite-radar' ),
					$version
				)
			)
		);
	}
}
