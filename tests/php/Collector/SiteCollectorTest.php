<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;
use RuntimeException;

final class SiteCollectorTest extends TestCase {

	public function tear_down(): void {
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
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
}
