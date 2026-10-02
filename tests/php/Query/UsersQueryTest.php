<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\UsersQuery;
use MultisiteRadar\Tests\TestCase;

final class UsersQueryTest extends TestCase {

	private int $site_a;
	private int $site_b;
	private int $solo;
	private int $multi;
	private int $nobody;

	public function set_up(): void {
		parent::set_up();
		$this->site_a = self::factory()->blog->create();
		$this->site_b = self::factory()->blog->create();
		$this->solo   = self::factory()->user->create(
			[
				'user_login'   => 'radar_solo',
				'display_name' => 'Solo',
			]
		);
		$this->multi  = self::factory()->user->create(
			[
				'user_login'   => 'radar_multi',
				'display_name' => 'Multi',
			]
		);
		$this->nobody = self::factory()->user->create(
			[
				'user_login'   => 'radar_nobody',
				'display_name' => 'Nobody',
			]
		);
		// La fabrique donne le rôle par défaut sur le site courant : on repart d'appartenances connues.
		foreach ( [ $this->solo, $this->multi, $this->nobody ] as $user ) {
			remove_user_from_blog( $user, get_current_blog_id() );
		}
		add_user_to_blog( $this->site_a, $this->solo, 'editor' );
		add_user_to_blog( $this->site_a, $this->multi, 'author' );
		add_user_to_blog( $this->site_b, $this->multi, 'author' );
	}

	private function query(): UsersQuery {
		return $this->plugin()->users_query();
	}

	private function logins( array $args ): array {
		return array_values( wp_list_pluck( $this->query()->list( array_merge( [ 'search' => 'radar_' ], $args ) )['items'], 'login' ) );
	}

	private function find( string $login ): array {
		return $this->query()->list( [ 'search' => $login ] )['items'][0];
	}

	public function test_counts_the_sites_of_every_account_without_email(): void {
		$result = $this->query()->list( [ 'search' => 'radar_' ] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ 'radar_multi', 'radar_nobody', 'radar_solo' ], wp_list_pluck( $result['items'], 'login' ) );
		$this->assertSame( [ 2, 0, 1 ], wp_list_pluck( $result['items'], 'sites_count' ) );
		$multi = $result['items'][0];
		$this->assertSame( [ 'id', 'login', 'display_name', 'super_admin', 'sites_count', 'registered_gmt', 'edit_url' ], array_keys( $multi ) );
		$this->assertSame( $this->multi, $multi['id'] );
		$this->assertSame( network_admin_url( 'user-edit.php?user_id=' . $this->multi ), $multi['edit_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $multi['registered_gmt'] );
	}

	public function test_keys_of_deleted_sites_and_lookalike_keys_are_not_memberships(): void {
		global $wpdb;
		update_user_meta( $this->nobody, $wpdb->base_prefix . '999999_capabilities', [ 'editor' => true ] );
		update_user_meta( $this->nobody, $wpdb->base_prefix . $this->site_a . '_foo_capabilities', [ 'editor' => true ] );

		$this->assertSame( 0, $this->find( 'radar_nobody' )['sites_count'] );
	}

	public function test_filters_sorting_and_pages(): void {
		$this->assertSame( [ 'radar_nobody' ], $this->logins( [ 'membership' => 'none' ] ) );
		$this->assertSame( [ 'radar_multi' ], $this->logins( [ 'membership' => 'several' ] ) );
		$this->assertSame( [ 'radar_multi', 'radar_nobody', 'radar_solo' ], $this->logins( [ 'membership' => 'many' ] ), 'Unknown values are ignored.' );
		$this->assertSame(
			[ 'radar_multi', 'radar_solo', 'radar_nobody' ],
			$this->logins(
				[
					'orderby' => 'sites_count',
					'order'   => 'desc',
				]
			)
		);
		$this->assertSame( [ 'radar_solo' ], $this->logins( [ 'search' => 'Solo' ] ), 'The display name is searched too.' );
		$this->assertSame(
			[ 'radar_nobody' ],
			$this->logins(
				[
					'per_page' => 1,
					'page'     => 2,
				]
			)
		);
	}

	public function test_the_super_admin_filter_never_lists_everyone(): void {
		grant_super_admin( $this->solo );
		$this->assertSame( [ 'radar_solo' ], $this->logins( [ 'super_admin' => true ] ) );
		$this->assertTrue( $this->find( 'radar_solo' )['super_admin'] );

		update_site_option( 'site_admins', [ 'radar_deleted_login' ] );
		$this->assertSame( [], $this->logins( [ 'super_admin' => true ] ) );

		update_site_option( 'site_admins', [] );
		$this->assertSame( [], $this->logins( [ 'super_admin' => true ] ) );
	}

	public function test_results_are_cached_until_a_membership_changes(): void {
		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, 'AS sites_count' ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			$first = $this->query()->list( [ 'search' => 'radar_' ] );
			$again = $this->query()->list( [ 'search' => 'radar_' ] );
			$this->assertSame( $first, $again );
			$this->assertSame( 1, $queries, 'One count of the memberships of the page, then the cache.' );

			add_user_to_blog( $this->site_b, $this->solo, 'subscriber' );
			$after = $this->query()->list( [ 'search' => 'radar_' ] );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertSame( 2, $queries, 'The same request is read again once a membership changed.' );
		$this->assertSame( [ 2, 0, 2 ], wp_list_pluck( $after['items'], 'sites_count' ) );
	}

	public function test_the_default_listing_does_not_aggregate_every_membership(): void {
		$queries  = [];
		$record = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		try {
			$result = $this->query()->list( [ 'search' => 'radar_' ] );
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertSame( [ 2, 0, 1 ], wp_list_pluck( $result['items'], 'sites_count' ) );
		$this->assertSame( 3, $result['total'] );
		$aggregated = array_filter( $queries, static fn ( string $query ): bool => false !== strpos( $query, 'GROUP BY m.user_id) AS c' ) );
		$this->assertSame( [], array_values( $aggregated ), 'No derived table over the whole usermeta.' );
		$counted = array_filter( $queries, static fn ( string $query ): bool => false !== strpos( $query, 'm.user_id IN (' ) );
		$this->assertCount( 1, $counted, 'Memberships are counted for the page only.' );
	}

	public function test_a_duplicate_key_for_the_main_site_counts_it_once(): void {
		global $wpdb;
		add_user_to_blog( 1, $this->nobody, 'subscriber' );
		update_user_meta( $this->nobody, $wpdb->base_prefix . '1_capabilities', [ 'subscriber' => true ] );

		$this->assertSame( 1, $this->find( 'radar_nobody' )['sites_count'] );
		$sorted = $this->query()->list(
			[
				'search'  => 'radar_nobody',
				'orderby' => 'sites_count',
			]
		);
		$this->assertSame( 1, $sorted['items'][0]['sites_count'], 'The derived-table path counts it once too.' );
		$this->assertNotContains( 'radar_nobody', $this->logins( [ 'membership' => 'several' ] ) );
	}
}
