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
				'user_email'   => 'solo@radar.test',
				'first_name'   => 'Sol',
				'last_name'    => 'Oyster',
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
		$this->assertSame( [ 'id', 'login', 'display_name', 'first_name', 'last_name', 'roles', 'published', 'super_admin', 'sites_count', 'registered_gmt', 'edit_url' ], array_keys( $multi ) );
		$this->assertArrayNotHasKey( 'email', $result['items'][0] );
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
		$counted = array_filter( $queries, static fn ( string $query ): bool => false !== strpos( $query, 'AS sites_count' ) && false !== strpos( $query, 'm.user_id IN (' ) );
		$this->assertCount( 1, $counted, 'Memberships are counted for the page only.' );
		// Les rôles relisent aussi les appartenances : jamais au-delà des comptes de la page.
		$memberships = array_filter( $queries, static fn ( string $query ): bool => false !== strpos( $query, 'm.meta_key REGEXP' ) );
		$unbounded   = array_filter( $memberships, static fn ( string $query ): bool => false === strpos( $query, 'm.user_id IN (' ) );
		$this->assertCount( 2, $memberships, 'One count and one read of the roles.' );
		$this->assertSame( [], array_values( $unbounded ), 'Every read of the memberships is bounded to the page.' );
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

	public function test_the_email_is_returned_and_searched_only_on_request(): void {
		$this->assertSame( 'solo@radar.test', $this->query()->list( [ 'search' => 'radar_solo', 'with_email' => true ] )['items'][0]['email'] );
		$this->assertSame( [ 'radar_solo' ], array_values( wp_list_pluck( $this->query()->list( [ 'search' => 'solo@radar', 'with_email' => true ] )['items'], 'login' ) ) );
		$this->assertSame( [], $this->query()->list( [ 'search' => 'solo@radar' ] )['items'] );
	}

	public function test_names_roles_and_search_by_first_or_last_name(): void {
		$solo  = $this->find( 'radar_solo' );
		$multi = $this->find( 'radar_multi' );

		$this->assertSame( 'Sol', $solo['first_name'] );
		$this->assertSame( 'Oyster', $solo['last_name'] );
		$this->assertSame( [ 'radar_solo' ], array_values( wp_list_pluck( $this->query()->list( [ 'search' => 'Oyst' ] )['items'], 'login' ) ) );
		$this->assertSame(
			[
				[
					'role'  => 'author',
					'label' => 'Author',
					'sites' => 2,
				],
			],
			$multi['roles']
		);
		$this->assertSame( [], $this->find( 'radar_nobody' )['roles'] );
	}

	public function test_a_list_read_in_one_locale_is_not_served_in_another(): void {
		$this->assertSame( 'Author', $this->find( 'radar_multi' )['roles'][0]['label'] );

		$locale    = static fn (): string => 'fr_FR';
		$translate = static fn ( string $translation, string $text, string $context ): string => 'Author' === $text && 'User role' === $context ? 'Auteur' : $translation;
		add_filter( 'determine_locale', $locale );
		add_filter( 'gettext_with_context', $translate, 10, 3 );
		try {
			$this->assertSame( 'Auteur', $this->find( 'radar_multi' )['roles'][0]['label'] );
		} finally {
			remove_filter( 'determine_locale', $locale );
			remove_filter( 'gettext_with_context', $translate, 10 );
		}
	}

	public function test_published_content_is_null_until_a_site_is_analysed_then_counted(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \MultisiteRadar\Install\Schema::authors_table() ) );
		$this->assertNull( $this->find( 'radar_multi' )['published'] );

		$this->plugin()->authors()->replace_for_site( $this->site_a, [ $this->multi => 2 ] );
		$this->plugin()->authors()->replace_for_site( $this->site_b, [ $this->multi => 3 ] );

		$this->assertSame( 5, $this->find( 'radar_multi' )['published'] );
		$this->assertSame( 0, $this->find( 'radar_solo' )['published'] );
		// À égalité (0), l'ordre est celui des identifiants : radar_solo a été créé avant radar_nobody.
		$this->assertSame(
			[ 'radar_multi', 'radar_solo', 'radar_nobody' ],
			$this->logins(
				[
					'orderby' => 'published',
					'order'   => 'desc',
				]
			)
		);
	}

	public function test_a_membership_filter_sorted_by_published_content_with_a_search(): void {
		add_user_to_blog( $this->site_b, $this->solo, 'author' );
		// Membre de plusieurs sites et premier par contenus publiés, mais hors de la recherche.
		$heavy = self::factory()->user->create( [ 'user_login' => 'elsewhere_heavy' ] );
		remove_user_from_blog( $heavy, get_current_blog_id() );
		add_user_to_blog( $this->site_a, $heavy, 'author' );
		add_user_to_blog( $this->site_b, $heavy, 'author' );
		$this->plugin()->authors()->replace_for_site(
			$this->site_a,
			[
				$this->multi => 2,
				$this->solo  => 1,
				$heavy       => 9,
			]
		);
		$this->plugin()->authors()->replace_for_site(
			$this->site_b,
			[
				$this->solo   => 4,
				$this->nobody => 7,
			]
		);

		// Table dérivée des appartenances, jointure des contenus publiés et recherche (e-mail compris) : chaque
		// fragment apporte ses paramètres à prepare().
		$result = $this->query()->list(
			[
				'search'     => 'radar_',
				'with_email' => true,
				'membership' => 'several',
				'orderby'    => 'published',
				'order'      => 'desc',
			]
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'radar_solo', 'radar_multi' ], wp_list_pluck( $result['items'], 'login' ) );
		$this->assertSame( [ 5, 2 ], wp_list_pluck( $result['items'], 'published' ) );
		$this->assertSame(
			[ 'radar_multi', 'radar_solo' ],
			$this->logins(
				[
					'membership' => 'several',
					'orderby'    => 'published',
				]
			)
		);
	}

	public function test_sorting_by_email_needs_the_email(): void {
		$this->assertSame(
			$this->logins( [ 'orderby' => 'login' ] ),
			$this->logins( [ 'orderby' => 'email' ] )
		);
	}

	public function test_the_detail_lists_the_sites_with_role_and_published_content(): void {
		$this->plugin()->authors()->replace_for_site( $this->site_a, [ $this->multi => 2 ] );

		$detail = $this->query()->get( $this->multi, false );

		$this->assertSame( 'radar_multi', $detail['login'] );
		$this->assertArrayNotHasKey( 'email', $detail );
		$this->assertSame( 2, $detail['sites_total'] );
		$by_id = array_column( $detail['sites'], null, 'id' );
		$this->assertSame(
			[
				[
					'role'  => 'author',
					'label' => 'Author',
				],
			],
			$by_id[ $this->site_a ]['roles']
		);
		$this->assertSame( 2, $by_id[ $this->site_a ]['published'] );
		$this->assertNull( $by_id[ $this->site_b ]['published'] );
		$this->assertStringContainsString( '/wp-admin/', $by_id[ $this->site_a ]['admin_url'] );
		$this->assertSame( 'solo@radar.test', $this->query()->get( $this->solo, true )['email'] );
		$this->assertNull( $this->query()->get( 999999, true ) );
	}

	public function test_the_detail_total_adds_only_the_sites_of_the_account(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \MultisiteRadar\Install\Schema::authors_table() ) );
		$this->assertNull( $this->query()->get( $this->multi, false )['published'], 'Nothing counted yet.' );

		$elsewhere = self::factory()->blog->create();
		$this->plugin()->authors()->replace_for_site( $this->site_a, [ $this->multi => 2 ] );
		$this->plugin()->authors()->replace_for_site( $this->site_b, [ $this->multi => 3 ] );
		// Un super-admin peut publier sur un site dont il n'est pas membre : la fiche ne liste pas ce site.
		$this->plugin()->authors()->replace_for_site( $elsewhere, [ $this->multi => 4 ] );

		$detail = $this->query()->get( $this->multi, false );

		$this->assertNotContains( $elsewhere, array_column( $detail['sites'], 'id' ) );
		$this->assertSame( 5, $detail['published'] );
		$this->assertSame( $detail['published'], array_sum( array_column( $detail['sites'], 'published' ) ) );
	}

	public function test_the_detail_lists_a_bounded_number_of_sites_and_counts_them_all(): void {
		$this->assertSame( 200, UsersQuery::PANEL_SITES );

		$detail = $this->query()->get( $this->multi, false, 1 );

		$this->assertSame( 2, $detail['sites_total'] );
		$this->assertCount( 1, $detail['sites'] );
	}
}
