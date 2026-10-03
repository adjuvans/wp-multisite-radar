<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class ListSitesAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->make_record(
			3801,
			[
				'name'         => 'Alpha',
				'url'          => 'example.org/radar-alpha/',
				'scanned_at'   => '2026-09-01 00:00:00',
				'disk_bytes'   => 2048,
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		// Jamais analysé : mesures et dates nulles.
		$this->make_record(
			3802,
			[
				'name' => 'Beta',
				'url'  => 'example.org/radar-beta/',
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function list_sites( $input ) {
		$ability = wp_get_ability( 'multisite-radar/list-sites' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_sites_with_their_measures_even_before_their_first_analysis(): void {
		$result = $this->list_sites(
			[
				'search'  => 'radar-',
				'orderby' => 'name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'Alpha', 'Beta' ], array_column( $result['items'], 'name' ) );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 20, $result['per_page'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertSame( 2048, $result['items'][0]['disk_bytes'] );
		$this->assertTrue( $result['items'][1]['pending'] );
		$this->assertNull( $result['items'][1]['disk_bytes'] );
		$this->assertNull( $result['items'][1]['scanned_at_gmt'] );
	}

	public function test_filters_by_alert_level_and_paginates(): void {
		$errors = $this->list_sites(
			[
				'search'      => 'radar-',
				'alert_level' => [ 'error' ],
			]
		);
		$this->assertSame( [ 'Alpha' ], array_column( $errors['items'], 'name' ) );

		$second = $this->list_sites(
			[
				'search'   => 'radar-',
				'orderby'  => 'name',
				'per_page' => 1,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'Beta' ], array_column( $second['items'], 'name' ) );
		$this->assertSame( 2, $second['total_pages'] );
	}

	public function test_numbers_sent_as_text_are_accepted(): void {
		$result = $this->list_sites(
			[
				'search'   => 'radar-',
				'per_page' => '1',
				'page'     => '1',
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 1, $result['per_page'] );
	}

	public function test_unknown_or_out_of_range_input_is_refused(): void {
		$inputs = [
			[ 'per_page' => 500 ],
			[ 'colour' => 'red' ],
			[ 'alert_level' => [ 'fatal' ] ],
			[ 'orderby' => 'password' ],
		];
		foreach ( $inputs as $input ) {
			$result = $this->list_sites( $input );
			$this->assertWPError( $result, (string) wp_json_encode( $input ) );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}
	}

	public function test_a_user_without_the_view_capability_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->list_sites( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
