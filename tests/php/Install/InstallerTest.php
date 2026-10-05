<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class InstallerTest extends TestCase {

	public function test_activate_installs_seeds_and_announces(): void {
		$site_id = self::factory()->blog->create();
		delete_site_option( Schema::OPTION );
		$calls = [];
		add_action(
			'msradar_activated',
			static function ( bool $network_wide ) use ( &$calls ): void {
				$calls[] = $network_wide;
			}
		);

		Installer::activate( true );

		$this->assertTrue( Schema::is_current() );
		$this->assertNotNull( $this->plugin()->sites()->find( $site_id ) );
		$this->assertSame( [ true ], $calls );
	}

	public function test_maybe_upgrade_runs_only_when_the_schema_is_outdated(): void {
		$runs = 0;
		add_action(
			'msradar_upgraded',
			static function () use ( &$runs ): void {
				++$runs;
			}
		);

		update_site_option( Schema::OPTION, Schema::VERSION );
		Installer::maybe_upgrade();
		$this->assertSame( 0, $runs );

		update_site_option( Schema::OPTION, 0 );
		Installer::maybe_upgrade();
		$this->assertSame( 1, $runs );
		$this->assertTrue( Schema::is_current() );
	}

	public function test_deactivate_announces(): void {
		$before = did_action( 'msradar_deactivated' );

		Installer::deactivate();

		$this->assertSame( $before + 1, did_action( 'msradar_deactivated' ) );
	}

	public function test_upgrade_is_hooked_on_admin_init(): void {
		$this->assertNotFalse( has_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] ) );
	}

	public function test_maybe_upgrade_passes_the_previous_schema_version(): void {
		$seen = [];
		add_action(
			'msradar_upgraded',
			static function ( $version, $previous ) use ( &$seen ): void {
				$seen[] = [ $version, $previous ];
			},
			10,
			2
		);

		update_site_option( Schema::OPTION, 3 );
		Installer::maybe_upgrade();

		$this->assertSame( [ [ Schema::VERSION, 3 ] ], $seen );
	}
}
