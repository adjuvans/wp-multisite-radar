<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;
use RuntimeException;

final class SiteCollectorTest extends TestCase {

	private string $uploads = '';

	public function tear_down(): void {
		if ( '' !== $this->uploads ) {
			self::remove_tree( $this->uploads );
			$this->uploads = '';
		}
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
	}

	/**
	 * Dossiers d'envoi factices, rangés comme ceux du cœur : la racine pour le site principal, sites/<id> pour les autres.
	 */
	private function fake_uploads(): string {
		$root = untrailingslashit( get_temp_dir() ) . '/msradar-uploads-' . wp_generate_password( 8, false );
		wp_mkdir_p( $root );
		add_filter(
			'upload_dir',
			static function ( array $uploads ) use ( $root ): array {
				$uploads['basedir'] = is_main_site() ? $root : $root . '/sites/' . get_current_blog_id();
				return $uploads;
			}
		);
		$this->uploads = $root;
		return $root;
	}

	private static function put_file( string $path, int $bytes ): void {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_tree( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	public function test_measures_the_upload_folder_of_the_site(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/2026/09/photo.jpg', 1500 );

		$record = $this->collect( $site_id );

		$this->assertSame( 1500, $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}

	public function test_a_site_without_upload_folder_uses_no_disk_space(): void {
		$this->fake_uploads();

		$record = $this->collect( self::factory()->blog->create() );

		$this->assertSame( 0, $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}

	public function test_the_main_site_does_not_count_the_folders_of_the_other_sites(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/logo.png', 100 );
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );

		$this->assertSame( 100, $this->collect( get_main_site_id() )->disk_bytes );
	}

	public function test_a_measure_cut_short_by_its_budget_is_an_estimate(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );
		add_filter( 'msradar_disk_budget', static fn (): float => 0.0 );

		$record = $this->collect( $site_id );

		$this->assertTrue( $record->disk_is_estimate );
		$this->assertLessThan( 1500, (int) $record->disk_bytes );
	}

	public function test_the_disk_measure_can_be_switched_off(): void {
		$root    = $this->fake_uploads();
		$site_id = self::factory()->blog->create();
		self::put_file( $root . '/sites/' . $site_id . '/photo.jpg', 1500 );
		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );

		$record = $this->collect( $site_id );

		$this->assertNull( $record->disk_bytes );
		$this->assertFalse( $record->disk_is_estimate );
	}

	private function collect( int $site_id ): SiteRecord {
		$record = ( new SiteCollector( $this->plugin()->settings() ) )->collect( $site_id );
		$this->assertNotNull( $record );
		return $record;
	}

	private function find( array $items, string $name ): ?array {
		foreach ( $items as $item ) {
			if ( $name === $item['name'] ) {
				return $item;
			}
		}
		return null;
	}

	public function test_counts_content_media_and_terms(): void {
		$site_id = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$before  = $this->collect( $site_id );

		switch_to_blog( $site_id );
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create_many( 2, [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		self::factory()->post->create_many( 2, [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] );
		self::factory()->category->create_many( 2 );
		restore_current_blog();

		$after = $this->collect( $site_id );

		$this->assertSame( 'Blog RH', $after->name );
		$this->assertSame( $site_id, $after->site_id );
		$this->assertSame( 5, $after->content_count - $before->content_count );
		$this->assertSame( 2, $after->media_count - $before->media_count );
		$this->assertSame( 4, $this->find( $after->data['post_types'], 'post' )['total'] - $this->find( $before->data['post_types'], 'post' )['total'] );
		$this->assertSame( 2, $this->find( $after->data['taxonomies'], 'category' )['count'] - $this->find( $before->data['taxonomies'], 'category' )['count'] );
		$this->assertNull( $this->find( $after->data['post_types'], 'revision' ), 'Excluded types are not listed.' );
		$this->assertFalse( $after->dirty );
		$this->assertNotNull( $after->scanned_at );
	}

	public function test_the_site_name_is_stored_as_plain_text_not_as_the_escaped_option(): void {
		$site_id = self::factory()->blog->create( [ 'title' => "L'atelier R&D" ] );
		$this->assertSame( 'L&#039;atelier R&amp;D', get_blog_option( $site_id, 'blogname' ), 'Core stores the site title escaped.' );

		$this->assertSame( "L'atelier R&D", $this->collect( $site_id )->name );

		switch_to_blog( $site_id );
		update_option( 'blogname', 'Café <Lab> "Ouest"' );
		restore_current_blog();
		$this->assertSame( 'Café <Lab> "Ouest"', $this->collect( $site_id )->name );
	}

	public function test_counts_users_by_role_and_lists_privileged_accounts(): void {
		$site_id = self::factory()->blog->create();
		$before  = $this->collect( $site_id );
		$editor  = self::factory()->user->create( [ 'user_login' => 'radar-editor' ] );
		$reader  = self::factory()->user->create( [ 'user_login' => 'radar-reader' ] );
		add_user_to_blog( $site_id, $editor, 'editor' );
		add_user_to_blog( $site_id, $reader, 'subscriber' );

		$after  = $this->collect( $site_id );
		$logins = wp_list_pluck( $after->data['users']['privileged'], 'login' );

		$this->assertSame( 2, $after->users_count - $before->users_count );
		$this->assertSame( 1, ( $after->data['users']['by_role']['editor'] ?? 0 ) - ( $before->data['users']['by_role']['editor'] ?? 0 ) );
		$this->assertContains( 'radar-editor', $logins );
		$this->assertNotContains( 'radar-reader', $logins );
	}

	public function test_fresh_registry_provides_labels_origins_and_verification(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		self::factory()->post->create_many( 2, [ 'post_type' => 'fixture_event' ] );
		update_option(
			RegistryProbe::OPTION,
			[
				'fingerprint' => Fingerprint::current(),
				'built_at'    => time(),
				'post_types'  => [
					'fixture_event' => [
						'label'   => 'Fixture events',
						'public'  => true,
						'show_ui' => true,
						'builtin' => false,
						'origin'  => [
							'kind' => 'plugin',
							'slug' => 'fixture-events',
						],
					],
				],
				'taxonomies'  => [],
			]
		);
		restore_current_blog();

		$record = $this->collect( $site_id );
		$event  = $this->find( $record->data['post_types'], 'fixture_event' );

		$this->assertSame( RegistryProbe::STATUS_FRESH, $record->registry_status );
		$this->assertSame( 'Fixture events', $event['label'] );
		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'fixture-events' ], $event['origin'] );
		$this->assertSame( 2, $event['publish'] );
		$this->assertTrue( $event['verified'] );
		$this->assertFalse( $event['builtin'] );
	}

	public function test_types_found_only_in_the_database_are_unverified(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$wpdb->insert(
			$wpdb->posts,
			[
				'post_type'             => 'legacy_thing',
				'post_status'           => 'publish',
				'post_title'            => 'Old',
				'post_content'          => '',
				'post_excerpt'          => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			]
		);
		restore_current_blog();

		$record = $this->collect( $site_id );
		$legacy = $this->find( $record->data['post_types'], 'legacy_thing' );
		$post   = $this->find( $record->data['post_types'], 'post' );

		$this->assertSame( RegistryProbe::STATUS_MISSING, $record->registry_status );
		$this->assertSame( 'legacy_thing', $legacy['label'] );
		$this->assertSame( [ 'kind' => 'unknown', 'slug' => '' ], $legacy['origin'] );
		$this->assertFalse( $legacy['verified'] );
		$this->assertSame( [ 'kind' => 'core', 'slug' => '' ], $post['origin'] );
		$this->assertTrue( $post['builtin'] );
	}

	public function test_stale_registry_keeps_labels_but_is_unverified(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option(
			$site_id,
			RegistryProbe::OPTION,
			[
				'fingerprint' => 'outdated',
				'built_at'    => time(),
				'post_types'  => [
					'post' => [
						'label'   => 'Articles',
						'public'  => true,
						'show_ui' => true,
						'builtin' => true,
						'origin'  => [
							'kind' => 'core',
							'slug' => '',
						],
					],
				],
				'taxonomies'  => [],
			]
		);

		$record = $this->collect( $site_id );
		$post   = $this->find( $record->data['post_types'], 'post' );

		$this->assertSame( RegistryProbe::STATUS_STALE, $record->registry_status );
		$this->assertSame( 'Articles', $post['label'] );
		$this->assertFalse( $post['verified'] );
	}

	public function test_reads_theme_local_plugins_and_locale(): void {
		$site_id = self::factory()->blog->create();
		update_site_option( 'active_sitewide_plugins', [ 'netwide/netwide.php' => time() ] );
		update_blog_option( $site_id, 'active_plugins', [ 'acme/acme.php', 'netwide/netwide.php' ] );
		update_blog_option( $site_id, 'stylesheet', 'child-theme' );
		update_blog_option( $site_id, 'template', 'parent-theme' );
		add_filter( 'get_available_languages', static fn (): array => [ 'fr_FR' ] );
		update_blog_option( $site_id, 'WPLANG', 'fr_FR' );

		$record = $this->collect( $site_id );

		$this->assertSame( [ 'acme/acme.php' ], $record->data['plugins_local'] );
		$this->assertSame( 'child-theme', $record->theme_stylesheet );
		$this->assertSame( 'parent-theme', $record->theme_template );
		$this->assertSame( 'fr_FR', $record->data['options']['locale'] );
	}

	public function test_last_activity_follows_the_configured_types(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$event_id = self::factory()->post->create(
			[
				'post_type'     => 'fixture_event',
				'post_date'     => '2025-06-01 10:00:00',
				'post_date_gmt' => '2025-06-01 08:00:00',
			]
		);
		restore_current_blog();
		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'fixture_event' ] ] ] );

		$record = $this->collect( $site_id );

		$this->assertSame( '2025-06-01 08:00:00', $record->last_activity_gmt );
		$this->assertSame( $event_id, $record->data['last_content']['id'] );
		$this->assertSame( 'fixture_event', $record->data['last_content']['type'] );
	}

	public function test_corrupted_or_empty_options_do_not_break_the_scan(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option( $site_id, 'active_plugins', 'not-an-array' );
		update_blog_option( $site_id, 'blogname', '' );
		update_blog_option( $site_id, RegistryProbe::OPTION, 'corrupt' );

		$record = $this->collect( $site_id );

		$this->assertSame( '', $record->name, 'The fallback name is applied when reading, in the reader’s language.' );
		$this->assertSame( [], $record->data['plugins_local'] );
		$this->assertSame( RegistryProbe::STATUS_MISSING, $record->registry_status );
	}

	public function test_throws_when_site_tables_are_missing(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->get_blog_prefix( $site_id ) . 'posts' ) );

		try {
			( new SiteCollector( $this->plugin()->settings() ) )->collect( $site_id );
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			$this->assertStringNotContainsString( '&#', $e->getMessage() );
		}
	}

	public function test_network_active_plugins_come_from_the_sites_own_network(): void {
		$network_id = self::factory()->network->create();
		update_network_option( $network_id, 'active_sitewide_plugins', [ 'acme/acme.php' => time() ] );
		$site_id = self::factory()->blog->create( [ 'network_id' => $network_id ] );
		update_blog_option( $site_id, 'active_plugins', [ 'acme/acme.php' ] );

		$record = $this->collect( $site_id );

		$this->assertSame( $network_id, $record->network_id );
		$this->assertSame( [], $record->data['plugins_local'] );
	}

	public function test_unknown_site_returns_null(): void {
		$this->assertNull( ( new SiteCollector( $this->plugin()->settings() ) )->collect( 999999 ) );
	}

	public function test_the_wordpress_address_is_stored_in_its_own_column(): void {
		$site_id = self::factory()->blog->create();
		update_blog_option( $site_id, 'siteurl', 'https://example.test/wp' );

		$this->assertSame( 'https://example.test/wp', $this->collect( $site_id )->siteurl );
	}

	public function test_weighs_the_autoloaded_options_only(): void {
		$site_id = self::factory()->blog->create();
		$before  = $this->collect( $site_id );
		switch_to_blog( $site_id );
		add_option( 'msradar_test_hot', str_repeat( 'a', 5000 ), '', true );
		add_option( 'msradar_test_cold', str_repeat( 'b', 7000 ), '', false );
		restore_current_blog();

		$this->assertSame( 5000, $this->collect( $site_id )->autoload_bytes - $before->autoload_bytes );
	}

	public function test_the_main_site_weighs_its_own_tables_only(): void {
		global $wpdb;
		$queries = [];
		$spy     = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, 'information_schema' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $spy );

		$this->assertGreaterThan( 0, (int) $record->db_bytes );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "'" . $wpdb->base_prefix . "posts'", $queries[0] );
		$this->assertStringNotContainsString( "'" . $wpdb->base_prefix . "users'", $queries[0] );
		$this->assertStringNotContainsString( "'" . $wpdb->base_prefix . "msradar_sites'", $queries[0] );
		$this->assertSame( 0, preg_match( "/'" . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $queries[0] ), 'No table of another site is listed.' );
	}

	public function test_the_table_status_is_read_when_information_schema_is_refused(): void {
		global $wpdb;
		$statuses = 0;
		$filter   = static function ( string $query ) use ( &$statuses ): string {
			if ( false !== strpos( $query, 'information_schema' ) ) {
				return 'SELECT * FROM msradar_no_such_table';
			}
			if ( 0 === strpos( $query, 'SHOW TABLE STATUS' ) ) {
				++$statuses;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $filter );

		$this->assertSame( 1, $statuses );
		$this->assertGreaterThan( 0, (int) $record->db_bytes );
	}

	public function test_the_database_size_is_unknown_when_no_source_answers(): void {
		$filter = static function ( string $query ): string {
			return false !== strpos( $query, 'information_schema' ) || 0 === strpos( $query, 'SHOW TABLE STATUS' )
				? 'SELECT * FROM msradar_no_such_table'
				: $query;
		};
		add_filter( 'query', $filter );
		$record = $this->collect( get_main_site_id() );
		remove_filter( 'query', $filter );

		$this->assertNull( $record->db_bytes );
		$this->assertGreaterThan( 0, $record->users_count, 'The rest of the analysis is unaffected.' );
	}

	public function test_site_tables_of_the_main_site_leave_out_other_sites_and_network_tables(): void {
		$names   = [ 'wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_msradar_sites', 'wp_2_posts', 'wp_12_options', 'wp_wc_orders', 'wp_2fa_codes' ];
		$network = [ 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_msradar_sites' ];

		$this->assertSame( [ 'wp_posts', 'wp_options', 'wp_wc_orders', 'wp_2fa_codes' ], SiteCollector::site_tables( $names, 'wp_', true, $network ) );
		$this->assertSame( [ 'wp_2_posts', 'wp_2_options' ], SiteCollector::site_tables( [ 'wp_2_posts', 'wp_2_options' ], 'wp_2_', false, $network ) );
	}

	public function test_counts_the_scheduled_tasks_already_due(): void {
		$now  = 1790000000;
		$cron = [
			$now - 7200 => [
				'hook_a' => [
					'k1' => [],
					'k2' => [],
				],
			],
			$now - 60   => [ 'hook_b' => [ 'k3' => [] ] ],
			$now + 60   => [ 'hook_c' => [ 'k4' => [] ] ],
			$now - 9000 => [],
			'version'   => 2,
		];

		$this->assertSame(
			[
				'overdue_count'      => 3,
				'oldest_overdue_gmt' => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
			],
			SiteCollector::overdue_tasks( $cron, $now )
		);
		$this->assertSame(
			[
				'overdue_count'      => 0,
				'oldest_overdue_gmt' => null,
			],
			SiteCollector::overdue_tasks( 'corrupted', $now )
		);
	}

	public function test_stores_the_overdue_tasks_and_the_own_upload_quota_of_the_site(): void {
		$site_id = self::factory()->blog->create();
		$due     = time() - 3 * HOUR_IN_SECONDS;
		update_blog_option(
			$site_id,
			'cron',
			[
				$due      => [
					'msradar_test' => [
						'abc' => [
							'schedule' => false,
							'args'     => [],
						],
					],
				],
				'version' => 2,
			]
		);
		update_blog_option( $site_id, 'blog_upload_space', '50' );

		$record = $this->collect( $site_id );

		$this->assertSame(
			[
				'overdue_count'      => 1,
				'oldest_overdue_gmt' => gmdate( 'Y-m-d H:i:s', $due ),
			],
			$record->data['cron']
		);
		$this->assertSame( 50, $record->data['options']['upload_space_mb'] );
		$this->assertNull( $this->collect( self::factory()->blog->create() )->data['options']['upload_space_mb'], 'A site without its own quota follows the network.' );
	}
}
