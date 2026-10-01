<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\SiteUsersQuery;
use MultisiteRadar\Tests\TestCase;

final class SiteUsersQueryTest extends TestCase {

	private int $site_id;

	public function set_up(): void {
		parent::set_up();
		// La fabrique ne rattache aucun utilisateur : l'utilisateur 1 (« admin », super-admin) devient administrateur du site.
		$this->site_id = self::factory()->blog->create();
		add_user_to_blog( $this->site_id, 1, 'administrator' );
		$zoe           = self::factory()->user->create(
			[
				'user_login'   => 'zoe',
				'display_name' => 'Zoé Martin',
			]
		);
		$adam          = self::factory()->user->create(
			[
				'user_login'   => 'adam',
				'display_name' => 'Adam',
			]
		);
		self::factory()->user->create( [ 'user_login' => 'outsider' ] );
		add_user_to_blog( $this->site_id, $zoe, 'editor' );
		add_user_to_blog( $this->site_id, $adam, 'author' );
	}

	private function query(): SiteUsersQuery {
		return $this->plugin()->site_users_query();
	}

	public function test_lists_only_the_users_of_the_site_without_email(): void {
		$result = $this->query()->list( $this->site_id, [] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ 'adam', 'admin', 'zoe' ], wp_list_pluck( $result['items'], 'login' ) );
		$zoe = $result['items'][2];
		$this->assertSame( [ 'id', 'login', 'display_name', 'roles', 'super_admin', 'registered_gmt' ], array_keys( $zoe ) );
		$this->assertSame( 'Zoé Martin', $zoe['display_name'] );
		$this->assertSame( [ 'editor' ], $zoe['roles'] );
		$this->assertFalse( $zoe['super_admin'] );
		$this->assertTrue( $result['items'][1]['super_admin'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $zoe['registered_gmt'] );
	}

	public function test_search_role_sorting_and_pagination(): void {
		$this->assertSame( [ 'zoe' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'search' => 'Zo' ] )['items'], 'login' ) );
		$this->assertSame( [ 'zoe' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'role' => 'editor' ] )['items'], 'login' ) );
		$this->assertSame( [ 'zoe', 'admin', 'adam' ], wp_list_pluck( $this->query()->list( $this->site_id, [ 'order' => 'desc' ] )['items'], 'login' ) );

		$page = $this->query()->list(
			$this->site_id,
			[
				'per_page' => 1,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'admin' ], wp_list_pluck( $page['items'], 'login' ) );
		$this->assertSame( 3, $page['total'] );
	}

	public function test_unknown_sites_and_sites_of_another_network_are_null(): void {
		$other      = self::factory()->network->create();
		$other_site = self::factory()->blog->create( [ 'network_id' => $other ] );

		$this->assertNull( $this->query()->list( 999999, [] ) );
		$this->assertNull( $this->query()->list( $other_site, [] ) );
	}
}
