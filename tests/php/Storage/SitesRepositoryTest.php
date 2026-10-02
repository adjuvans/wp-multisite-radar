<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Tests\TestCase;

final class SitesRepositoryTest extends TestCase {

	private SitesRepository $sites;

	public function set_up(): void {
		parent::set_up();
		$this->sites = $this->plugin()->sites();
	}

	public function test_save_and_find_round_trip(): void {
		$record                    = new SiteRecord();
		$record->site_id           = 501;
		$record->name              = 'Blog RH';
		$record->url               = 'https://example.org/rh/';
		$record->users_count       = 3;
		$record->last_activity_gmt = '2026-09-12 08:41:00';
		$record->alert_rules       = ',inactive,';
		$record->data              = [ 'post_types' => [ [ 'name' => 'post' ] ] ];
		$record->dirty             = false;

		$this->sites->save( $record );
		$found = $this->sites->find( 501 );

		$this->assertNotNull( $found );
		$this->assertSame( 'Blog RH', $found->name );
		$this->assertSame( 3, $found->users_count );
		$this->assertNull( $found->disk_bytes );
		$this->assertSame( '2026-09-12 08:41:00', $found->last_activity_gmt );
		$this->assertSame( [ 'inactive' ], $found->alert_rule_ids() );
		$this->assertSame( [ 'post_types' => [ [ 'name' => 'post' ] ] ], $found->data );
		$this->assertFalse( $found->dirty );
		$this->assertNull( $this->sites->find( 999999 ) );
	}

	public function test_seed_inserts_one_pending_row_per_site_and_is_idempotent(): void {
		$site_id = self::factory()->blog->create();

		$this->sites->seed_from_blogs( get_current_network_id() );
		$record = $this->sites->find( $site_id );

		$this->assertNotNull( $record );
		$this->assertTrue( $record->dirty );
		$this->assertNull( $record->scanned_at );
		$this->assertNotNull( $this->sites->find( get_main_site_id() ), 'The main site is seeded too.' );
		$this->assertSame( 0, $this->sites->seed_from_blogs( get_current_network_id() ) );
	}

	public function test_mark_dirty_keeps_the_earliest_mark_and_orders_the_queue(): void {
		global $wpdb;
		$this->sites->insert_pending( 601, 1, 'a.test/' );
		$this->sites->insert_pending( 602, 1, 'b.test/' );
		$this->mark_all_clean();

		$this->sites->mark_dirty( [ 602 ] );
		$wpdb->update( Schema::sites_table(), [ 'dirty_since' => '2020-01-01 00:00:00' ], [ 'site_id' => 602 ] );
		$this->sites->mark_dirty( [ 601, 602, 0, -3 ] );

		$this->assertSame( '2020-01-01 00:00:00', $this->sites->find( 602 )->dirty_since, 'An existing mark is kept.' );
		$this->assertSame( [ 602, 601 ], $this->sites->next_dirty( 10 ) );
		$this->assertSame( [ 601, 602 ], $this->sites->dirty_ids() );
		$this->assertSame( 2, $this->sites->count_dirty() );
	}

	public function test_count_dirty_can_be_scoped_to_a_network(): void {
		$network_id = get_current_network_id();
		$this->make_record( 801, [ 'dirty' => true ] );
		$this->make_record( 802, [ 'dirty' => true, 'network_id' => $network_id + 1000 ] );

		$this->assertSame( 2, $this->sites->count_dirty() );
		$this->assertSame( 1, $this->sites->count_dirty( $network_id ) );
		$this->assertSame( 1, $this->sites->count_dirty( $network_id + 1000 ) );
	}

	public function test_queue_lookups_can_be_scoped_to_a_network(): void {
		$network_id = get_current_network_id();
		$other      = $network_id + 1000;
		$this->make_record( 811, [ 'dirty' => true ] );
		$this->make_record( 812, [ 'dirty' => true, 'network_id' => $other ] );
		$this->make_record( 813, [ 'network_id' => $other ] );
		$this->make_record( 814 );

		$this->assertSame( [ 811, 812 ], $this->sites->next_dirty( 10 ) );
		$this->assertSame( [ 811 ], $this->sites->next_dirty( 10, $network_id ) );
		$this->assertSame( [ 812 ], $this->sites->next_dirty( 10, $other ) );
		$this->assertSame( [ 811, 812 ], $this->sites->dirty_ids() );
		$this->assertSame( [ 812 ], $this->sites->dirty_ids( $other ) );
		$this->assertSame( [ 812, 813, 814 ], $this->sites->ids_after( 811, 10 ) );
		$this->assertSame( [ 811, 814 ], $this->sites->ids_after( 0, 10, $network_id ) );
		$this->assertSame( [ 813 ], $this->sites->ids_after( 812, 10, $other ) );
	}

	public function test_save_never_overwrites_a_mark_set_during_the_scan(): void {
		$this->sites->insert_pending( 701, 1, 'c.test/' );

		$record          = new SiteRecord();
		$record->site_id = 701;
		$record->name    = 'Scanned';
		$record->dirty   = false;
		$this->sites->save( $record );
		$found = $this->sites->find( 701 );

		$this->assertSame( 'Scanned', $found->name );
		$this->assertTrue( $found->dirty, 'The pending mark survives.' );
	}

	public function test_last_activity_only_moves_forward(): void {
		$this->sites->insert_pending( 801, 1, 'd.test/' );

		$this->sites->update_last_activity( 801, '2026-01-01 00:00:00' );
		$this->sites->update_last_activity( 801, '2025-01-01 00:00:00' );

		$this->assertSame( '2026-01-01 00:00:00', $this->sites->find( 801 )->last_activity_gmt );
	}

	public function test_delete_orphans_removes_rows_without_a_site(): void {
		$this->sites->insert_pending( 901, 1, 'gone.test/' );
		$this->sites->seed_from_blogs( get_current_network_id() );

		$this->assertSame( 1, $this->sites->delete_orphans() );
		$this->assertNull( $this->sites->find( 901 ) );
		$this->assertNotNull( $this->sites->find( get_main_site_id() ) );
	}

	public function test_find_many_counts_and_pagination_helpers(): void {
		$this->make_record( 1001, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 1002 );
		$this->make_record( 1003 );

		$this->assertSame( [ 1001, 1003 ], array_keys( $this->sites->find_many( [ 1003, 1001, 4242 ] ) ) );
		$this->assertSame( [ 1002, 1003 ], $this->sites->ids_after( 1001, 10 ) );
		$this->assertSame( 3, $this->sites->count_all( 1 ) );
		$this->assertSame( 2, $this->sites->count_pending( 1 ) );
	}

	public function test_save_alerts_updates_only_alert_columns(): void {
		$record               = $this->make_record( 1101, [ 'name' => 'Keep me' ] );
		$record->alert_level  = 3;
		$record->alerts_count = 1;
		$record->alert_rules  = ',no_users,';
		$record->data         = [ 'alerts' => [ [ 'rule' => 'no_users' ] ] ];
		$record->name         = 'Ignored';

		$this->sites->save_alerts( $record );
		$found = $this->sites->find( 1101 );

		$this->assertSame( 3, $found->alert_level );
		$this->assertSame( [ 'no_users' ], $found->alert_rule_ids() );
		$this->assertSame( 'Keep me', $found->name );
	}

	public function test_save_truncates_overlong_strings_instead_of_losing_the_record(): void {
		$this->sites->insert_pending( 1201, 1, 'e.test/' );
		$record          = new SiteRecord();
		$record->site_id = 1201;
		$record->name    = str_repeat( 'n', 300 );
		$record->dirty   = false;
		$this->sites->save( $record );

		$this->assertSame( str_repeat( 'n', 255 ), $this->sites->find( 1201 )->name );

		$new          = new SiteRecord();
		$new->site_id = 1202;
		$new->name    = str_repeat( 'é', 300 );
		$this->sites->save( $new );

		$this->assertSame( str_repeat( 'é', 255 ), $this->sites->find( 1202 )->name );
	}

	public function test_list_rows_do_not_read_the_detailed_data_and_cannot_be_saved(): void {
		$this->make_record(
			501,
			[
				'name'       => 'Listed',
				'scanned_at' => '2026-09-01 00:00:00',
				'data'       => [ 'post_types' => [ [ 'name' => 'post' ] ] ],
			]
		);
		$selects = [];
		$spy     = static function ( string $query ) use ( &$selects ): string {
			if ( 0 === strpos( ltrim( $query ), 'SELECT site_id' ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		$args    = [
			'network_id'      => get_current_network_id(),
			'page'            => 1,
			'per_page'        => 20,
			'search'          => 'Listed',
			'orderby'         => 'name',
			'order'           => 'asc',
			'alert_level'     => [],
			'status'          => [],
			'theme'           => '',
			'plugin'          => '',
			'has_users'       => null,
			'inactive_since'  => null,
			'registry_status' => [],
			'rule'            => '',
		];
		add_filter( 'query', $spy );
		try {
			$result = $this->plugin()->sites()->query( $args );
		} finally {
			remove_filter( 'query', $spy );
		}

		$this->assertCount( 1, $selects );
		$this->assertDoesNotMatchRegularExpression( '/\bdata\b/', $selects[0] );
		$record = $result['items'][0];
		$this->assertTrue( $record->partial );
		$this->assertSame( [], $record->data );
		$this->assertFalse( $this->plugin()->sites()->find( 501 )->partial );

		$this->expectException( \LogicException::class );
		$this->plugin()->sites()->save( $record );
	}

	public function test_theme_counts_separate_active_themes_and_parents_per_network(): void {
		$this->make_record(
			981,
			[
				'theme_stylesheet' => 'child',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record(
			982,
			[
				'theme_stylesheet' => 'parent',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record(
			983,
			[
				'network_id'       => 2,
				'theme_stylesheet' => 'parent',
				'theme_template'   => 'parent',
			]
		);
		$this->make_record( 984 );

		$counts = $this->plugin()->sites()->theme_counts( get_current_network_id() );

		$this->assertSame(
			[
				'child'  => 1,
				'parent' => 1,
			],
			$counts['active']
		);
		$this->assertSame( [ 'parent' => 1 ], $counts['parent'] );
	}
}
