<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Capabilities;
use MultisiteRadar\Tests\TestCase;

final class MenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		set_current_screen( 'dashboard-network' );
		$GLOBALS['menu']                = [];
		$GLOBALS['submenu']             = [];
		$GLOBALS['_wp_submenu_nopriv']  = [];
		$GLOBALS['_registered_pages']   = [];
		$GLOBALS['_parent_pages']       = [];
	}

	private function slugs(): array {
		return array_column( $GLOBALS['submenu']['multisite-radar'] ?? [], 2 );
	}

	public function test_registers_one_page_per_view_for_super_admins(): void {
		$menu = new Menu();
		$menu->add_pages();

		$this->assertSame( [ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-plugins', 'multisite-radar-themes', 'multisite-radar-users', 'multisite-radar-alerts', 'multisite-radar-settings' ], $this->slugs() );
		$this->assertSame( 'sites', $menu->view_for_hook( (string) get_plugin_page_hookname( 'multisite-radar-sites', 'multisite-radar' ) ) );
		$this->assertNull( $menu->view_for_hook( 'index.php' ) );
	}

	public function test_settings_require_the_manage_capability(): void {
		$map = static function (): array {
			return [
				Capabilities::VIEW   => 'manage_network',
				Capabilities::MANAGE => 'do_not_allow',
			];
		};
		add_filter( 'msradar_capability_map', $map );
		try {
			( new Menu() )->add_pages();
		} finally {
			remove_filter( 'msradar_capability_map', $map );
		}

		$this->assertSame( [ 'multisite-radar', 'multisite-radar-sites', 'multisite-radar-plugins', 'multisite-radar-themes', 'multisite-radar-users', 'multisite-radar-alerts' ], $this->slugs() );
	}

	public function test_render_outputs_the_application_container_for_the_current_page(): void {
		$_GET['page'] = 'multisite-radar-alerts';
		ob_start();
		( new Menu() )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['page'] );

		$this->assertStringContainsString( '<div id="msradar-app" class="msradar-app" data-view="alerts"></div>', $html );
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Alerts</h1>', $html );
		$this->assertStringContainsString( Menu::version_badge( MSRADAR_VERSION ), $html );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_the_version_badge_flags_pre_releases(): void {
		$this->assertSame( '<span class="msradar-version">2.0.0</span>', Menu::version_badge( '2.0.0' ) );
		$this->assertSame( '<span class="msradar-version is-prerelease" title="Pre-release version">2.0.0-beta.1</span>', Menu::version_badge( '2.0.0-beta.1' ) );
		$this->assertSame( '<span class="msradar-version">&lt;b&gt;</span>', Menu::version_badge( '<b>' ) );
	}

	public function test_urls_point_to_the_network_admin(): void {
		$this->assertSame( network_admin_url( 'admin.php?page=multisite-radar-sites&rule=no_users' ), Menu::url( 'sites', [ 'rule' => 'no_users' ] ) );
	}
}
