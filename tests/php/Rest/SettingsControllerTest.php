<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class SettingsControllerTest extends RestTestCase {

	public function test_requires_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 403, $this->request( 'GET', '/settings' )->get_status() );
	}

	public function test_get_and_update(): void {
		$this->login_as_super_admin();

		$this->assertSame( 7, $this->request( 'GET', '/settings' )->get_data()['scan']['full_rescan_days'] );

		$response = $this->request( 'POST', '/settings', [ 'scan' => [ 'full_rescan_days' => 3 ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['scan']['full_rescan_days'] );
	}

	/**
	 * @dataProvider invalid_bodies
	 */
	public function test_rejects_invalid_bodies( array $body, string $code ): void {
		$this->login_as_super_admin();

		$response = $this->request( 'POST', '/settings', $body );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	public static function invalid_bodies(): array {
		return [
			'out of range'        => [ [ 'scan' => [ 'full_rescan_days' => 0 ] ], 'msradar_invalid_settings' ],
			'unknown rule'        => [ [ 'alerts' => [ 'rules' => [ 'nope' => [ 'enabled' => false ] ] ] ], 'msradar_unknown_rule' ],
			'invalid rule params' => [ [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'params' => [ 'months' => 0 ] ] ] ] ], 'msradar_invalid_settings' ],
		];
	}
}
