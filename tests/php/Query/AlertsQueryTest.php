<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class AlertsQueryTest extends TestCase {

	public function test_summary_counts_sites_by_severity_and_rule(): void {
		$scanned = '2026-09-01 00:00:00';
		$this->make_record( 301, [ 'scanned_at' => $scanned, 'alert_level' => 3, 'alerts_count' => 2, 'alert_rules' => ',no_users,high_media,' ] );
		$this->make_record( 302, [ 'scanned_at' => $scanned, 'alert_level' => 2, 'alerts_count' => 1, 'alert_rules' => ',inactive,' ] );
		$this->make_record( 303, [ 'scanned_at' => $scanned ] );
		$this->make_record( 304 );

		$summary = $this->plugin()->alerts_query()->summary( get_current_network_id() );

		$this->assertSame( 4, $summary['total_sites'] );
		$this->assertSame( 3, $summary['scanned_sites'] );
		$this->assertSame( 1, $summary['pending_sites'] );
		$this->assertSame( 2, $summary['sites_with_alerts'] );
		$this->assertSame( [ 'error' => 1, 'warning' => 1, 'info' => 0 ], $summary['by_severity'] );
		$this->assertSame( array_keys( $this->plugin()->rules()->all() ), wp_list_pluck( $summary['by_rule'], 'rule' ) );
		$this->assertSame(
			[
				[ 'rule' => 'no_users', 'label' => 'Site without users', 'severity' => 'error', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'inactive', 'label' => 'Inactive site', 'severity' => 'warning', 'enabled' => true, 'count' => 1 ],
				[ 'rule' => 'high_media', 'label' => 'Many media files', 'severity' => 'info', 'enabled' => true, 'count' => 1 ],
			],
			array_slice( $summary['by_rule'], 0, 3 )
		);
		$this->assertSame( [ 0 ], array_values( array_unique( wp_list_pluck( array_slice( $summary['by_rule'], 3 ), 'count' ) ) ), 'No site raises the other rules.' );
	}

	private function seed_alerts(): void {
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			601,
			[
				'name'         => 'Alpha',
				'url'          => 'https://alpha.test/',
				'scanned_at'   => $scanned,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
				'data'         => [ 'alerts' => [ [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ] ] ],
			]
		);
		$this->make_record(
			602,
			[
				'name'         => 'Beta',
				'url'          => 'https://beta.test/',
				'scanned_at'   => $scanned,
				'alert_level'  => 2,
				'alerts_count' => 2,
				'alert_rules'  => ',inactive,high_media,',
				'data'         => [
					'alerts' => [
						[ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ],
						[ 'rule' => 'high_media', 'severity' => 'info', 'args' => [ 'count' => 1500, 'threshold' => 1000 ] ],
					],
				],
			]
		);
		$this->make_record(
			603,
			[
				'name'        => 'Elsewhere',
				'network_id'  => 2,
				'scanned_at'  => $scanned,
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
			]
		);
		$this->make_record( 604, [ 'name' => 'Clean', 'scanned_at' => $scanned ] );
	}

	private function ids( array $args ): array {
		return wp_list_pluck( $this->plugin()->alerts_query()->list( $args )['items'], 'id' );
	}

	public function test_lists_one_row_per_site_and_rule_of_the_network_sorted_by_rule(): void {
		$this->seed_alerts();

		$result = $this->plugin()->alerts_query()->list( [] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ '602:high_media', '602:inactive', '601:no_users' ], wp_list_pluck( $result['items'], 'id' ) );
		$this->assertSame(
			[
				'id'       => '602:inactive',
				'site'     => [
					'id'        => 602,
					'name'      => 'Beta',
					'url'       => 'https://beta.test/',
					'admin_url' => 'https://beta.test/wp-admin/',
				],
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => $this->plugin()->rules()->get( 'inactive' )->message( [ 'months' => 8 ] ),
			],
			$result['items'][1]
		);
	}

	public function test_filters_sorting_search_and_pagination(): void {
		$this->seed_alerts();

		$this->assertSame( [ '601:no_users' ], $this->ids( [ 'severity' => [ 'error' ] ] ) );
		$this->assertSame( [ '602:inactive' ], $this->ids( [ 'rule' => [ 'inactive', 'unknown' ] ] ) );
		$this->assertSame( [ '601:no_users', '602:inactive', '602:high_media' ], $this->ids( [ 'orderby' => 'severity', 'order' => 'desc' ] ) );
		$this->assertSame( [ '601:no_users', '602:high_media', '602:inactive' ], $this->ids( [ 'orderby' => 'name' ] ) );
		$this->assertSame( [ '602:high_media', '602:inactive' ], $this->ids( [ 'search' => 'beta' ] ) );

		$page = $this->plugin()->alerts_query()->list( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( [ '601:no_users' ], wp_list_pluck( $page['items'], 'id' ) );
		$this->assertSame( 3, $page['total'] );
	}

	public function test_disabled_rules_and_overridden_severities_follow_the_settings(): void {
		$this->seed_alerts();
		$this->plugin()->settings()->update(
			[
				'alerts' => [
					'rules' => [
						'high_media' => [ 'enabled' => false ],
						'inactive'   => [ 'severity' => 'error' ],
					],
				],
			]
		);

		$this->assertSame( [ '602:inactive', '601:no_users' ], $this->ids( [ 'severity' => [ 'error' ] ] ) );

		$summary = $this->plugin()->alerts_query()->summary( get_current_network_id() );
		$by_rule = array_column( $summary['by_rule'], null, 'rule' );
		$this->assertFalse( $by_rule['high_media']['enabled'] );
		$this->assertSame( 'error', $by_rule['inactive']['severity'] );
	}

	public function test_an_empty_rule_selection_returns_nothing(): void {
		$this->seed_alerts();

		$this->assertSame( [], $this->ids( [ 'severity' => [ 'info' ], 'rule' => [ 'no_users' ] ] ) );
	}
}
