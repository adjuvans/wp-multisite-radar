<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Footer;
use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Tests\TestCase;

final class FooterTest extends TestCase {

	private Menu $menu;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		set_current_screen( 'dashboard-network' );
		$GLOBALS['menu']               = [];
		$GLOBALS['submenu']            = [];
		$GLOBALS['_wp_submenu_nopriv'] = [];
		$GLOBALS['_registered_pages']  = [];
		$GLOBALS['_parent_pages']      = [];
		$this->menu = new Menu();
		$this->menu->add_pages();
	}

	public function tear_down(): void {
		unset( $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	private function footer( string $build_dir = '' ): Footer {
		return new Footer( $this->menu, '' === $build_dir ? dirname( __DIR__ ) . '/fixtures/build/' : $build_dir, 'https://example.test/build/' );
	}

	private function on_page( string $slug ): void {
		$GLOBALS['hook_suffix'] = (string) get_plugin_page_hookname( $slug, 'multisite-radar' );
	}

	public function test_plugin_pages_credit_adjuvans_and_link_the_licenses(): void {
		$this->on_page( 'multisite-radar-sites' );
		$html = $this->footer()->credits( 'Thank you for creating with WordPress.' );

		$this->assertStringNotContainsString( 'Thank you', $html );
		$this->assertStringContainsString( 'Multisite Radar by <a href="https://adjuvans.fr">ADJUVANS</a>', $html );
		$this->assertStringContainsString( '<a href="mailto:contact@adjuvans.fr">contact@adjuvans.fr</a>', $html );
		$this->assertStringContainsString( 'License: <a href="https://www.gnu.org/licenses/gpl-3.0.html">GPL-3.0 or later</a>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/build/third-party-licenses.txt">Third-party licenses</a>', $html );
	}

	public function test_the_third_party_link_is_left_out_when_the_build_has_no_license_file(): void {
		$this->on_page( 'multisite-radar' );
		$html = $this->footer( sys_get_temp_dir() . '/msradar-no-build/' )->credits( '' );

		$this->assertStringContainsString( 'ADJUVANS', $html );
		$this->assertStringNotContainsString( 'Third-party licenses', $html );
	}

	public function test_plugin_pages_show_the_plugin_version_instead_of_wordpress_version(): void {
		$this->on_page( 'multisite-radar-settings' );

		$this->assertSame( 'Multisite Radar ' . MSRADAR_VERSION, $this->footer()->version( 'Version 6.9' ) );
	}

	public function test_other_admin_pages_keep_the_wordpress_footer(): void {
		$GLOBALS['hook_suffix'] = 'index.php';
		$footer                 = $this->footer();

		$this->assertSame( 'Thank you for creating with WordPress.', $footer->credits( 'Thank you for creating with WordPress.' ) );
		$this->assertSame( 'Version 6.9', $footer->version( 'Version 6.9' ) );
	}

	public function test_registers_both_footer_filters(): void {
		$footer = $this->footer();
		$footer->register();

		$this->assertSame( 20, has_filter( 'admin_footer_text', [ $footer, 'credits' ] ) );
		$this->assertSame( 20, has_filter( 'update_footer', [ $footer, 'version' ] ) );
	}
}
