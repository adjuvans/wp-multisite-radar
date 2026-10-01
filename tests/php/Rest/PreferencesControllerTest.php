<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\RestTestCase;

final class PreferencesControllerTest extends RestTestCase {

	public function test_requires_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/preferences' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/preferences' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/preferences', [ 'sites' => [ 'per_page' => 50 ] ] )->get_status() );
	}

	public function test_reads_and_updates_the_current_users_preferences_only(): void {
		$this->login_as_super_admin();
		$this->assertSame( Preferences::defaults(), $this->request( 'GET', '/preferences' )->get_data() );

		$response = $this->request( 'POST', '/preferences', [ 'sites' => [ 'layout' => 'grid' ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'grid', $response->get_data()['sites']['layout'] );

		$this->login_as_super_admin();
		$this->assertSame( 'table', $this->request( 'GET', '/preferences' )->get_data()['sites']['layout'], 'Each user has their own preferences.' );
	}

	public function test_rejects_invalid_or_empty_bodies(): void {
		$this->login_as_super_admin();

		$this->assertSame( 400, $this->request( 'POST', '/preferences', [] )->get_status() );
		$response = $this->request( 'POST', '/preferences', [ 'sites' => [ 'per_page' => 7 ] ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'msradar_invalid_preferences', $response->get_data()['code'] );
	}
}
