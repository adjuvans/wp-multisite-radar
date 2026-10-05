<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\ChangeLog;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class ChangeLogTest extends TestCase {

	private const NOW = '2026-09-10 12:00:00';

	private function state( array $plugins, string $theme, array $alerts, ?string $scanned_at = '2026-09-01 00:00:00' ): SiteRecord {
		$record                       = new SiteRecord();
		$record->site_id              = 4400;
		$record->network_id           = 77;
		$record->scanned_at           = $scanned_at;
		$record->theme_stylesheet     = $theme;
		$record->data['plugins_local'] = $plugins;
		$record->data['alerts']        = array_map(
			static function ( string $rule, string $severity ): array {
				return [
					'rule'     => $rule,
					'severity' => $severity,
					'args'     => [],
				];
			},
			array_keys( $alerts ),
			array_values( $alerts )
		);
		return $record;
	}

	public function test_the_first_analysis_is_the_reference_and_records_nothing(): void {
		$after = $this->state( [ 'a/a.php' ], 'twentytwentyfive', [ 'no_users' => 'error' ] );

		$this->assertSame( [], ChangeLog::diff( null, $after, self::NOW ) );
		$this->assertSame( [], ChangeLog::diff( $this->state( [], '', [], null ), $after, self::NOW ) );
	}

	public function test_plugins_theme_and_alerts_that_changed_become_events(): void {
		$before = $this->state( [ 'a/a.php', 'b/b.php' ], 'parent', [ 'inactive' => 'warning' ] );
		$after  = $this->state( [ 'b/b.php', 'c/c.php' ], 'child', [ 'no_users' => 'error' ] );

		$events = ChangeLog::diff( $before, $after, self::NOW );

		$this->assertSame(
			[
				[ 'plugin_activated', 'c/c.php', [] ],
				[ 'plugin_deactivated', 'a/a.php', [] ],
				[ 'theme_switched', 'child', [ 'from' => 'parent' ] ],
				[ 'alert_raised', 'no_users', [ 'severity' => 'error' ] ],
				[ 'alert_resolved', 'inactive', [ 'severity' => 'warning' ] ],
			],
			array_map(
				static function ( array $event ): array {
					return [ $event['type'], $event['subject'], $event['meta'] ];
				},
				$events
			)
		);
		foreach ( $events as $event ) {
			$this->assertSame( 77, $event['network_id'] );
			$this->assertSame( 4400, $event['site_id'] );
			$this->assertSame( self::NOW, $event['created_at'] );
		}
	}

	private function with_site_plugins( SiteRecord $record, array $site_plugins ): SiteRecord {
		$record->data['plugins_site'] = $site_plugins;
		return $record;
	}

	public function test_network_activating_a_locally_active_plugin_records_nothing_on_the_site(): void {
		$before = $this->with_site_plugins( $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ), [ 'a/a.php' ] );
		$after  = $this->with_site_plugins( $this->state( [], 'twentytwentyfive', [] ), [ 'a/a.php' ] );

		$this->assertSame( [], ChangeLog::diff( $before, $after, self::NOW ) );
	}

	public function test_network_deactivating_a_locally_active_plugin_records_nothing_on_the_site(): void {
		$before = $this->with_site_plugins( $this->state( [], 'twentytwentyfive', [] ), [ 'a/a.php' ] );
		$after  = $this->with_site_plugins( $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ), [ 'a/a.php' ] );

		$this->assertSame( [], ChangeLog::diff( $before, $after, self::NOW ) );
	}

	public function test_a_genuine_local_activation_is_still_recorded_with_the_site_plugins(): void {
		$before = $this->with_site_plugins( $this->state( [], 'twentytwentyfive', [] ), [] );
		$after  = $this->with_site_plugins( $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ), [ 'a/a.php' ] );

		$events = ChangeLog::diff( $before, $after, self::NOW );

		$this->assertSame( [ 'plugin_activated' ], array_column( $events, 'type' ) );
		$this->assertSame( [ 'a/a.php' ], array_column( $events, 'subject' ) );
	}

	public function test_a_before_record_without_site_plugins_compares_the_local_plugins(): void {
		$before = $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] );
		$after  = $this->with_site_plugins( $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ), [ 'a/a.php', 'n/n.php' ] );

		$this->assertSame( [], ChangeLog::diff( $before, $after, self::NOW ) );
	}

	public function test_a_severity_change_alone_records_nothing(): void {
		$before = $this->state( [], 'twentytwentyfive', [ 'inactive' => 'warning' ] );
		$after  = $this->state( [], 'twentytwentyfive', [ 'inactive' => 'error' ] );

		$this->assertSame( [], ChangeLog::diff( $before, $after, self::NOW ) );
	}

	public function test_compare_and_record_write_to_the_journal(): void {
		$log = $this->plugin()->change_log();
		$log->compare( $this->state( [], 'twentytwentyfive', [] ), $this->state( [ 'a/a.php' ], 'twentytwentyfive', [] ) );
		$log->record( 77, 0, 'plugin_activated', 'n/n.php', [ 'network' => true ] );

		$items = $this->plugin()->events()->query(
			[
				'network_id' => 77,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 20,
			]
		)['items'];

		$this->assertEqualsCanonicalizing( [ 'a/a.php', 'n/n.php' ], array_column( $items, 'subject' ) );
		$this->assertContains( 0, array_column( $items, 'site_id' ) );
	}

	public function test_a_failed_write_is_reported_and_never_thrown(): void {
		global $wpdb;
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'INSERT INTO msradar_missing_table VALUES (1)' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->change_log()->record( 77, 1, 'site_created', 'example.org/' );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ ChangeLog::class ], $reported );
	}
}
