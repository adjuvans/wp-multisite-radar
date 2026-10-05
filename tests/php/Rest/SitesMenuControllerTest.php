<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class SitesMenuControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
	}

	private function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	public function test_an_editor_finds_the_public_sites_by_name(): void {
		// Pas « Blog » : le site principal de la suite de tests s'appelle « Test Blog ».
		$rh        = self::factory()->blog->create( [ 'title' => 'Radar RH' ] );
		$marketing = self::factory()->blog->create( [ 'title' => 'Radar Équipe marketing' ] );
		self::factory()->blog->create(
			[
				'title'  => 'Radar privé',
				'public' => 0,
			]
		);
		self::factory()->blog->create( [ 'title' => 'Intranet' ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites', [ 'search' => 'radar' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				[
					'id'   => $marketing,
					'name' => 'Radar Équipe marketing',
				],
				[
					'id'   => $rh,
					'name' => 'Radar RH',
				],
			],
			$response->get_data()
		);
	}

	public function test_the_search_ignores_case_and_accents_and_matches_an_id(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Équipe' ] );
		$this->login_as( 'editor' );

		$this->assertSame( [ $site ], wp_list_pluck( $this->request( 'GET', '/sites-menu/sites', [ 'search' => 'equipe' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $site ], wp_list_pluck( $this->request( 'GET', '/sites-menu/sites', [ 'search' => '#' . $site ] )->get_data(), 'id' ) );
	}

	public function test_chosen_sites_are_read_back_by_id_with_their_name(): void {
		$first  = self::factory()->blog->create( [ 'title' => 'Zeta' ] );
		$second = self::factory()->blog->create( [ 'title' => 'Alpha' ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites', [ 'include' => [ $first, $second, 999999 ] ] );

		$this->assertSame( [ 'Alpha', 'Zeta' ], wp_list_pluck( $response->get_data(), 'name' ) );
	}

	public function test_results_are_capped_by_per_page(): void {
		self::factory()->blog->create_many( 3 );
		$this->login_as( 'editor' );

		$this->assertCount( 2, $this->request( 'GET', '/sites-menu/sites', [ 'per_page' => 2 ] )->get_data() );
		$this->assertSame( 400, $this->request( 'GET', '/sites-menu/sites', [ 'per_page' => 51 ] )->get_status() );
	}

	public function test_a_subscriber_cannot_list_the_sites(): void {
		$this->login_as( 'subscriber' );

		$this->assertSame( 403, $this->request( 'GET', '/sites-menu/sites' )->get_status() );
	}

	public function test_the_route_answers_404_while_the_module_is_disabled(): void {
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => false ] ] );
		$this->login_as( 'editor' );

		$response = $this->request( 'GET', '/sites-menu/sites' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'msradar_sites_menu_disabled', $response->as_error()->get_error_code() );
	}
}
