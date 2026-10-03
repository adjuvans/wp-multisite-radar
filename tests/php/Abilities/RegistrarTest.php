<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Abilities\Registrar;
use MultisiteRadar\Tests\RestTestCase;
use WP_REST_Request;

final class RegistrarTest extends RestTestCase {

	private const NAMES = [
		'multisite-radar/network-summary',
		'multisite-radar/list-sites',
	];

	public function test_the_abilities_are_registered_read_only_in_their_category(): void {
		$this->assertNotNull( wp_get_ability_category( Registrar::CATEGORY ) );
		foreach ( self::NAMES as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertSame( Registrar::CATEGORY, $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $name );
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertTrue( $annotations['readonly'], $name );
			$this->assertFalse( $annotations['destructive'], $name );
			$this->assertTrue( $annotations['idempotent'], $name );
		}
	}

	public function test_mcp_exposure_follows_the_setting_and_is_off_by_default(): void {
		$registrar = $this->plugin()->abilities();
		$this->assertSame( self::NAMES, array_keys( $registrar->definitions() ) );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertFalse( $args['meta']['mcp']['public'], $name );
			$this->assertSame( 'tool', $args['meta']['mcp']['type'], $name );
			$this->assertArrayNotHasKey( 'public', $args['meta'], 'meta.public would open every other channel too.' );
		}

		$this->plugin()->settings()->update( [ 'integrations' => [ 'mcp_public' => true ] ] );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertTrue( $args['meta']['mcp']['public'], $name );
		}

		$this->plugin()->settings()->update( [ 'integrations' => [ 'mcp_public' => false ] ] );
		foreach ( $registrar->definitions() as $name => $args ) {
			$this->assertFalse( $args['meta']['mcp']['public'], $name );
		}
	}

	public function test_the_abilities_are_offered_on_the_main_site_only(): void {
		$this->assertTrue( Registrar::available() );
		switch_to_blog( self::factory()->blog->create() );
		try {
			$this->assertFalse( Registrar::available() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_an_ability_runs_over_rest_with_get_and_the_view_capability_only(): void {
		$route = '/wp-abilities/v1/abilities/multisite-radar/network-summary/run';
		$this->assertSame( 401, $this->server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );

		$this->login_as_super_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'scan', 'alerts', 'inventory' ], array_keys( $response->get_data() ) );
		$this->assertSame( 405, $this->server->dispatch( new WP_REST_Request( 'POST', $route ) )->get_status(), 'A read-only ability only runs with GET.' );
	}
}
