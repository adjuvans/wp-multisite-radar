<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class ListAlertsAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			4201,
			[
				'name'         => 'Radar alert one',
				'scanned_at'   => $scanned,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$this->make_record(
			4202,
			[
				'name'         => 'Radar alert two',
				'scanned_at'   => $scanned,
				'alert_level'  => 2,
				'alerts_count' => 1,
				'alert_rules'  => ',inactive,',
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function list_alerts( $input ) {
		$ability = wp_get_ability( 'multisite-radar/list-alerts' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_one_entry_per_site_and_rule(): void {
		$result = $this->list_alerts(
			[
				'search'  => 'Radar alert',
				'orderby' => 'name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'no_users', 'inactive' ], array_column( $result['items'], 'rule' ) );
		$this->assertSame( 4201, $result['items'][0]['site']['id'] );
		$this->assertSame( 'error', $result['items'][0]['severity'] );
		$this->assertNotSame( '', $result['items'][0]['message'] );
	}

	public function test_filters_by_severity_and_by_rule(): void {
		$errors = $this->list_alerts(
			[
				'search'   => 'Radar alert',
				'severity' => [ 'error' ],
			]
		);
		$this->assertSame( [ 4201 ], array_column( array_column( $errors['items'], 'site' ), 'id' ) );

		$inactive = $this->list_alerts(
			[
				'search' => 'Radar alert',
				'rule'   => [ 'inactive' ],
			]
		);
		$this->assertSame( [ 4202 ], array_column( array_column( $inactive['items'], 'site' ), 'id' ) );
	}

	public function test_a_list_sent_as_comma_separated_text_filters_like_an_array(): void {
		$by_severity = $this->list_alerts(
			[
				'search'   => 'Radar alert',
				'severity' => 'error',
			]
		);
		$this->assertSame( [ 4201 ], array_column( array_column( $by_severity['items'], 'site' ), 'id' ) );

		$several = $this->list_alerts(
			[
				'search'   => 'Radar alert',
				'severity' => 'error,warning',
			]
		);
		$this->assertEqualsCanonicalizing( [ 4201, 4202 ], array_column( array_column( $several['items'], 'site' ), 'id' ) );

		$by_rule = $this->list_alerts(
			[
				'search' => 'Radar alert',
				'rule'   => 'inactive',
			]
		);
		$this->assertSame( [ 4202 ], array_column( array_column( $by_rule['items'], 'site' ), 'id' ) );

		$both = $this->list_alerts(
			[
				'search' => 'Radar alert',
				'rule'   => 'inactive, no_users',
			]
		);
		$this->assertEqualsCanonicalizing( [ 4201, 4202 ], array_column( array_column( $both['items'], 'site' ), 'id' ) );
	}

	public function test_a_disabled_rule_is_left_out(): void {
		$this->plugin()->settings()->update( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ] );

		$result = $this->list_alerts( [ 'search' => 'Radar alert' ] );

		$this->assertSame( [ 'no_users' ], array_column( $result['items'], 'rule' ) );
	}

	public function test_an_unknown_rule_or_severity_is_refused(): void {
		$this->assertSame( 'ability_invalid_input', $this->list_alerts( [ 'rule' => [ 'acme_missing' ] ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->list_alerts( [ 'severity' => [ 'none' ] ] )->get_error_code() );
	}
}
