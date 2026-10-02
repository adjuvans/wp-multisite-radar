<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Plugin;

final class PluginTest extends TestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( get_file_data( MSRADAR_FILE, [ 'Version' => 'Version' ] )['Version'], MSRADAR_VERSION, 'The header and the constant agree; bin/version.mjs keeps the other copies in sync.' );
		$this->assertFileExists( MSRADAR_FILE );
		$this->assertStringEndsWith( '/', MSRADAR_DIR );
	}

	public function test_suite_runs_in_multisite(): void {
		$this->assertTrue( is_multisite() );
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_translations_shipped_in_the_languages_folder_are_registered_on_init(): void {
		$this->assertSame( 10, has_action( 'init', [ Plugin::instance(), 'load_textdomain' ] ) );
		Plugin::instance()->load_textdomain();
		// Locale sans fichier de traduction : le registre se rabat sur le dossier déclaré par le plugin.
		$this->assertSame( WP_PLUGIN_DIR . '/' . dirname( plugin_basename( MSRADAR_FILE ) ) . '/languages/', $GLOBALS['wp_textdomain_registry']->get( 'multisite-radar', 'xx_XX' ) );
	}
}
