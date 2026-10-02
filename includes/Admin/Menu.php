<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Menu « Multisite Radar » de l'administration réseau : une page par vue, chacune avec le conteneur de l'application.
 */
final class Menu {

	public const PAGES = [
		'overview' => 'multisite-radar',
		'sites'    => 'multisite-radar-sites',
		'alerts'   => 'multisite-radar-alerts',
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
			'alerts'   => __( 'Alerts', 'multisite-radar' ),
			'settings' => __( 'Settings', 'multisite-radar' ),
		];
	}

	public function add_pages(): void {
		$parent = self::PAGES['overview'];
		add_menu_page( __( 'Multisite Radar', 'multisite-radar' ), __( 'Multisite Radar', 'multisite-radar' ), Capabilities::VIEW, $parent, [ $this, 'render' ], 'dashicons-chart-area', 30 );
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
	 * Pastille de version affichée à côté du titre ; une version préliminaire (« -beta.1 », « -rc.2 »…) est signalée.
	 */
	public static function version_badge( string $version ): string {
		if ( false === strpos( $version, '-' ) ) {
			return sprintf( '<span class="msradar-version">%s</span>', esc_html( $version ) );
		}
		return sprintf(
			'<span class="msradar-version is-prerelease" title="%1$s">%2$s</span>',
			esc_attr__( 'Pre-release version', 'multisite-radar' ),
			esc_html( $version )
		);
	}
}
