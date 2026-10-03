<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class NetworkSummaryAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function summary() {
		$ability = wp_get_ability( 'multisite-radar/network-summary' );
		$this->assertNotNull( $ability );
		return $ability->execute();
	}

	public function test_sums_up_the_analysis_the_alerts_and_the_inventory(): void {
		$before = $this->summary();
		$this->assertIsArray( $before );
		$this->make_record(
			3901,
			[
				'name'         => 'Broken',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$this->plugin()->reset_caches();

		$after = $this->summary();

		$this->assertIsArray( $after );
		$this->assertSame( [ 'scan', 'alerts', 'inventory' ], array_keys( $after ) );
		$this->assertSame( $before['scan']['total'] + 1, $after['scan']['total'] );
		$this->assertSame( $before['alerts']['by_severity']['error'] + 1, $after['alerts']['by_severity']['error'] );
		$this->assertArrayHasKey( 'unused', $after['inventory']['plugins'] );
	}

	public function test_a_failed_read_is_an_error_not_an_empty_summary(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$result = $this->summary();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_storage_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
