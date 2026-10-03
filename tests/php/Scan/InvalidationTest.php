<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Scan\Invalidation;
use MultisiteRadar\Tests\TestCase;

final class InvalidationTest extends TestCase {

	private int $site_id;

	public function set_up(): void {
		parent::set_up();
		$this->site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();
	}

	public function tear_down(): void {
		if ( post_type_exists( 'fixture_event' ) ) {
			unregister_post_type( 'fixture_event' );
		}
		parent::tear_down();
	}

	private function dirty( int $site_id ): bool {
		return $this->plugin()->sites()->find( $site_id )->dirty;
	}

	public function test_a_new_site_gets_a_pending_row(): void {
		$site_id = self::factory()->blog->create();
		$record  = $this->plugin()->sites()->find( $site_id );

		$this->assertNotNull( $record );
		$this->assertTrue( $record->dirty );
		$this->assertNull( $record->scanned_at );
	}

	public function test_a_deleted_site_loses_its_rows(): void {
		$this->plugin()->extensions()->replace_for_site( $this->site_id, [ 'acme/acme.php' ], 'theme', 'theme' );

		wp_delete_site( $this->site_id );

		$this->assertNull( $this->plugin()->sites()->find( $this->site_id ) );
		$this->assertSame( [], $this->plugin()->extensions()->for_site( $this->site_id ) );
	}

	public function test_a_local_plugin_change_marks_only_the_current_site(): void {
		switch_to_blog( $this->site_id );
		do_action( 'activated_plugin', 'acme/acme.php', false );
		restore_current_blog();

		$this->assertTrue( $this->dirty( $this->site_id ) );
		$this->assertFalse( $this->dirty( get_main_site_id() ) );
	}

	public function test_a_network_plugin_change_marks_every_site(): void {
		do_action( 'deactivated_plugin', 'acme/acme.php', true );

		$this->assertTrue( $this->dirty( $this->site_id ) );
		$this->assertTrue( $this->dirty( get_main_site_id() ) );
	}

	public function test_theme_switch_marks_the_site(): void {
		switch_to_blog( $this->site_id );
		do_action( 'switch_theme', 'Other', null, null );
		restore_current_blog();

		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_membership_changes_mark_the_site(): void {
		$user_id = self::factory()->user->create();

		add_user_to_blog( $this->site_id, $user_id, 'editor' );
		$this->assertTrue( $this->dirty( $this->site_id ) );

		$this->mark_all_clean();
		remove_user_from_blog( $user_id, $this->site_id );
		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_deleting_a_network_user_marks_all_their_sites(): void {
		$user_id = self::factory()->user->create();
		add_user_to_blog( $this->site_id, $user_id, 'editor' );
		$this->mark_all_clean();

		do_action( 'wpmu_delete_user', $user_id, get_userdata( $user_id ) );

		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_option_and_status_changes_mark_the_site(): void {
		update_blog_option( $this->site_id, 'blogname', 'Renamed' );
		$this->assertTrue( $this->dirty( $this->site_id ) );

		$this->mark_all_clean();
		update_blog_status( $this->site_id, 'archived', '1' );
		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_changing_the_upload_quota_of_a_site_marks_it(): void {
		update_blog_option( $this->site_id, 'blog_upload_space', 500 );

		$this->assertTrue( $this->dirty( $this->site_id ) );
	}

	public function test_publishing_updates_last_activity_without_marking(): void {
		switch_to_blog( $this->site_id );
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		restore_current_blog();

		$record = $this->plugin()->sites()->find( $this->site_id );
		$this->assertNotNull( $record->last_activity_gmt );
		$this->assertFalse( $record->dirty );
	}

	public function test_drafts_and_untracked_types_leave_last_activity_alone(): void {
		register_post_type( 'fixture_event', [ 'public' => true ] );

		switch_to_blog( $this->site_id );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create( [ 'post_type' => 'fixture_event' ] );
		restore_current_blog();

		$this->assertNull( $this->plugin()->sites()->find( $this->site_id )->last_activity_gmt );
	}

	public function test_handlers_do_nothing_before_the_schema_is_installed(): void {
		delete_site_option( Schema::OPTION );

		switch_to_blog( $this->site_id );
		do_action( 'switch_theme', 'Other', null, null );
		restore_current_blog();

		$this->assertFalse( $this->dirty( $this->site_id ) );
	}

	public function test_a_storage_failure_during_site_deletion_does_not_break_the_core_hook(): void {
		global $wpdb;
		$site_id  = self::factory()->blog->create();
		$errors   = [];
		$on_error = static function ( $context ) use ( &$errors ): void {
			$errors[] = $context;
		};
		$later    = false;
		$probe    = static function () use ( &$later ): void {
			$later = true;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, Schema::extensions_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $on_error );
		add_action( 'wp_delete_site', $probe, 99 );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			wp_delete_site( $site_id );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'wp_delete_site', $probe, 99 );
			remove_action( 'msradar_error', $on_error );
		}

		$this->assertTrue( $later, 'Later subscribers of wp_delete_site still run.' );
		$this->assertSame( [ Invalidation::class . '::on_site_deleted' ], $errors );
	}
}
