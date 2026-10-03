<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class NetworkStateWatcherTest extends TestCase {

	/**
	 * Le recalcul a déjà vu l'état actuel : plus rien n'est planifié.
	 */
	private function settle(): void {
		$this->plugin()->state_watcher()->check();
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
	}

	private function recompute_scheduled(): bool {
		return false !== wp_next_scheduled( Queue::HOOK_RECOMPUTE );
	}

	private static function updates( string $version, int $checked ): object {
		return (object) [
			'last_checked' => $checked,
			'response'     => [ 'akismet/akismet.php' => (object) [ 'new_version' => $version ] ],
		];
	}

	public function test_an_unchanged_state_schedules_nothing(): void {
		$this->settle();

		$this->plugin()->state_watcher()->check();

		$this->assertFalse( $this->recompute_scheduled() );
	}

	public function test_a_new_version_available_restarts_the_recompute_from_the_first_site(): void {
		$this->settle();
		update_site_option(
			Queue::RECOMPUTE_CURSOR,
			[
				'after'  => 5,
				'config' => 'x',
			]
		);

		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );

		$this->assertTrue( $this->recompute_scheduled() );
		$this->assertFalse( get_site_option( Queue::RECOMPUTE_CURSOR ) );
	}

	public function test_a_new_update_check_without_new_versions_schedules_nothing(): void {
		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );
		$this->settle();

		set_site_transient( 'update_plugins', self::updates( '9.0', 2 ) );

		$this->assertFalse( $this->recompute_scheduled() );
	}

	public function test_deleting_the_update_list_is_not_a_change_but_its_rebuild_is(): void {
		set_site_transient( 'update_plugins', self::updates( '9.0', 1 ) );
		$this->settle();

		// WordPress efface la liste après une mise à jour (wp_clean_plugins_cache()) : ce n'est pas « aucune mise à jour ».
		delete_site_transient( 'update_plugins' );
		$this->assertFalse( $this->recompute_scheduled() );

		// La reconstruction, sans le plugin mis à jour, relance le recalcul.
		set_site_transient(
			'update_plugins',
			(object) [
				'last_checked' => 2,
				'response'     => [],
			]
		);
		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_enabling_upload_quotas_restarts_the_recompute(): void {
		update_site_option( 'upload_space_check_disabled', 1 );
		$this->settle();

		update_site_option( 'upload_space_check_disabled', 0 );

		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_only_the_address_of_the_main_site_matters(): void {
		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->settle();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		update_option( 'home', 'https://example.org/other' );
		restore_current_blog();
		$this->assertFalse( $this->recompute_scheduled(), 'Another site moving to https changes nothing for the network.' );

		update_option( 'home', 'https://example.org' );
		$this->assertTrue( $this->recompute_scheduled() );
	}

	public function test_nothing_happens_while_the_schema_is_outdated(): void {
		$this->settle();
		update_site_option( Schema::OPTION, 2 );

		set_site_transient( 'update_plugins', self::updates( '9.1', 1 ) );

		$this->assertFalse( $this->recompute_scheduled() );
	}
}
