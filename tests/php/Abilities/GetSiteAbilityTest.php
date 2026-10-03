<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class GetSiteAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function get_site( $input ) {
		$ability = wp_get_ability( 'multisite-radar/get-site' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_returns_the_sheet_of_a_site_even_before_its_first_analysis(): void {
		$this->make_record( 4001, [ 'name' => 'Never analysed' ] );

		$site = $this->get_site( [ 'id' => 4001 ] );

		$this->assertIsArray( $site );
		$this->assertSame( 'Never analysed', $site['name'] );
		$this->assertTrue( $site['pending'] );
		$this->assertNull( $site['db_bytes'] );
		$this->assertNull( $site['cron'] );
		$this->assertInstanceOf( \stdClass::class, $site['options'], 'Encoded {} like the REST route.' );
	}

	public function test_privileged_accounts_are_named_by_login_without_email_addresses(): void {
		$this->make_record(
			4002,
			[
				'name'       => 'Team',
				'url'        => 'example.org/team/',
				'scanned_at' => '2026-09-01 00:00:00',
				'data'       => [
					'users' => [
						'total'      => 1,
						'by_role'    => [ 'administrator' => 1 ],
						'privileged' => [
							[
								'id'    => 7,
								'login' => 'chief',
								'roles' => [ 'administrator' ],
							],
						],
					],
				],
			]
		);

		$site = $this->get_site( [ 'id' => '4002' ] );

		$this->assertIsArray( $site );
		$this->assertSame( 'chief', $site['users']['privileged'][0]['login'] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $site ) );
	}

	public function test_an_unknown_site_or_a_site_of_another_network_is_not_found(): void {
		$this->make_record(
			4003,
			[
				'name'       => 'Elsewhere',
				'network_id' => 2,
			]
		);

		foreach ( [ 999999, 4003 ] as $id ) {
			$result = $this->get_site( [ 'id' => $id ] );
			$this->assertWPError( $result );
			$this->assertSame( 'msradar_site_not_found', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	public function test_the_site_id_is_required(): void {
		$this->assertSame( 'ability_invalid_input', $this->get_site( [] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->get_site( null )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->get_site( [ 'id' => 0 ] )->get_error_code() );
	}

	public function test_a_user_without_the_view_capability_is_refused(): void {
		$this->make_record( 4004, [ 'name' => 'Private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 'ability_invalid_permissions', $this->get_site( [ 'id' => 4004 ] )->get_error_code() );
	}
}
