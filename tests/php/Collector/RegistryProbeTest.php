<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Collector\OriginResolver;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Tests\TestCase;

final class RegistryProbeTest extends TestCase {

	public function tear_down(): void {
		foreach ( [ 'fixture_event', 'test_untracked' ] as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		if ( taxonomy_exists( 'fixture_genre' ) ) {
			unregister_taxonomy( 'fixture_genre' );
		}
		parent::tear_down();
	}

	private static function fixture_dir(): string {
		return dirname( __DIR__ ) . '/fixtures/plugins';
	}

	private function probe(): RegistryProbe {
		return new RegistryProbe(
			$this->plugin()->sites(),
			new OriginResolver( self::fixture_dir(), '/nonexistent/mu-plugins', [ '/nonexistent/themes' ], '/nonexistent/self' )
		);
	}

	public function test_status_and_due(): void {
		$now   = 1800000000;
		$fresh = [
			'fingerprint' => 'abc',
			'built_at'    => $now - 10,
		];

		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( false, 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( 'corrupt', 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_MISSING, RegistryProbe::status( [ 'fingerprint' => 'abc' ], 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_FRESH, RegistryProbe::status( $fresh, 'abc', $now ) );
		$this->assertSame( RegistryProbe::STATUS_STALE, RegistryProbe::status( $fresh, 'other', $now ) );
		$this->assertSame(
			RegistryProbe::STATUS_STALE,
			RegistryProbe::status(
				[
					'fingerprint' => 'abc',
					'built_at'    => $now - WEEK_IN_SECONDS - 1,
				],
				'abc',
				$now
			)
		);
		$this->assertTrue( RegistryProbe::is_due( false, 'abc', $now ) );
		$this->assertFalse( RegistryProbe::is_due( $fresh, 'abc', $now ) );
	}

	public function test_build_records_labels_and_origins_of_tracked_registrations(): void {
		$probe = $this->probe();
		$probe->start_tracking();
		require_once self::fixture_dir() . '/fixture-events/fixture-events.php';
		msradar_fixture_register_types();
		register_post_type( 'test_untracked', [ 'label' => 'Untracked' ] );

		$registry = $probe->build();

		$this->assertSame( Fingerprint::current(), $registry['fingerprint'] );
		$this->assertEqualsWithDelta( time(), $registry['built_at'], 5 );
		$this->assertSame( 'Fixture events', $registry['post_types']['fixture_event']['label'] );
		$this->assertTrue( $registry['post_types']['fixture_event']['public'] );
		$this->assertFalse( $registry['post_types']['fixture_event']['builtin'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $registry['post_types']['fixture_event']['origin'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $registry['taxonomies']['fixture_genre']['origin'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $registry['post_types']['post']['origin'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $registry['taxonomies']['category']['origin'] );
		$this->assertSame( [ 'kind' => 'unknown', 'slug' => '' ], $registry['post_types']['test_untracked']['origin'] );
	}

	public function test_run_saves_an_autoloaded_option_and_marks_the_site(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$sites   = $this->plugin()->sites();
		$sites->insert_pending( $site_id, get_current_network_id(), 'probe.test/' );
		$sites->clear_dirty( $site_id );

		switch_to_blog( $site_id );
		$this->probe()->run();
		$registry = get_option( RegistryProbe::OPTION );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, RegistryProbe::OPTION ) );
		restore_current_blog();

		$this->assertIsArray( $registry );
		$this->assertArrayHasKey( 'post', $registry['post_types'] );
		$this->assertContains( $autoload, wp_autoload_values_to_autoload() );
		$this->assertTrue( $sites->find( $site_id )->dirty );
	}

	public function test_register_hooks_the_schedule_only_when_due(): void {
		delete_option( RegistryProbe::OPTION );
		$due = $this->probe();
		$due->register();
		$this->assertSame( 100, has_action( 'init', [ $due, 'schedule' ] ) );
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $due, 'run' ] ) );

		update_option(
			RegistryProbe::OPTION,
			[
				'fingerprint' => Fingerprint::current(),
				'built_at'    => time(),
			]
		);
		$fresh = $this->probe();
		$fresh->register();
		$this->assertFalse( has_action( 'init', [ $fresh, 'schedule' ] ) );
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $fresh, 'run' ] ) );
	}

	public function test_schedule_adds_a_single_event(): void {
		wp_clear_scheduled_hook( RegistryProbe::CRON_HOOK );
		$probe = $this->probe();

		$probe->schedule();
		$probe->schedule();

		$this->assertSame( 1, $this->count_cron_events( RegistryProbe::CRON_HOOK ) );
	}

	public function test_plugin_boot_registers_the_probe(): void {
		$this->assertSame( 10, has_action( RegistryProbe::CRON_HOOK, [ $this->plugin()->probe(), 'run' ] ) );
	}
}
