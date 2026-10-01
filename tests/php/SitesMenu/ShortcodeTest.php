<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\SitesMenu\Shortcode;
use MultisiteRadar\Tests\TestCase;

final class ShortcodeTest extends TestCase {

	public function tear_down(): void {
		remove_shortcode( Shortcode::TAG );
		remove_shortcode( Shortcode::LEGACY_TAG );
		parent::tear_down();
	}

	private function enable(): void {
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
	}

	public function test_nothing_is_registered_while_the_module_is_disabled(): void {
		remove_shortcode( Shortcode::TAG );

		$this->plugin()->sites_menu()->init();

		$this->assertFalse( shortcode_exists( Shortcode::TAG ) );
	}

	public function test_the_shortcode_lists_public_sites_with_a_safe_wrapper(): void {
		self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->enable();

		$this->plugin()->sites_menu()->init();
		$html = do_shortcode( '[msradar_sites class="menu-a" wrapper="script"]' );

		$this->assertStringStartsWith( '<ul class="menu-a">', $html );
		$this->assertStringContainsString( '>Blog RH</a>', $html );
		$this->assertFalse( shortcode_exists( Shortcode::LEGACY_TAG ), 'No 1.x alias without a migrated 1.x install.' );
	}

	public function test_the_1x_aliases_exist_after_a_migration(): void {
		self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->enable();
		update_site_option( LegacyMigration::ALIASES, 1 );

		$this->plugin()->sites_menu()->init();

		$this->assertStringStartsWith( '<ul class="network-sites-menu">', do_shortcode( '[network_sites_menu]' ) );
		$this->assertTrue( function_exists( 'rdc_network_sites_menu' ) );
		ob_start();
		rdc_network_sites_menu( 'ol', 'legacy' );
		$this->assertStringStartsWith( '<ol class="legacy">', (string) ob_get_clean() );
	}

	public function test_a_1x_shortcode_still_registered_by_the_old_plugin_is_left_alone(): void {
		add_shortcode(
			Shortcode::LEGACY_TAG,
			static function (): string {
				return 'from 1.x';
			}
		);
		$this->enable();
		update_site_option( LegacyMigration::ALIASES, 1 );

		$this->plugin()->sites_menu()->init();

		$this->assertSame( 'from 1.x', do_shortcode( '[network_sites_menu]' ) );
	}
}
