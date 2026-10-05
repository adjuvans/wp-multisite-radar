<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class EventsQueryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha &amp; Co',
						'Version' => '1.0',
					],
				],
			],
			'plugins'
		);
		$this->make_record(
			4601,
			[
				'name'       => 'Current site',
				'url'        => 'example.org/current/',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
	}

	private function insert( string $type, int $site_id, string $subject, array $meta = [], string $created_at = '2026-09-10 10:00:00' ): void {
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => get_current_network_id(),
					'site_id'    => $site_id,
					'type'       => $type,
					'subject'    => $subject,
					'meta'       => $meta,
					'created_at' => $created_at,
				],
			]
		);
	}

	/**
	 * @return array[]
	 */
	private function items( array $args = [] ): array {
		return $this->plugin()->events_query()->list( array_merge( [ 'site' => 4601 ], $args ) )['items'];
	}

	public function test_a_plugin_event_names_the_plugin_and_its_site(): void {
		$this->insert( 'plugin_activated', 4601, 'alpha/alpha.php' );

		$item = $this->items()[0];

		$this->assertSame( 'plugin_activated', $item['type'] );
		$this->assertSame( 4601, $item['site']['id'] );
		$this->assertSame( 'Current site', $item['site']['name'] );
		$this->assertSame( 'Alpha & Co', $item['label'], 'Plain text, decoded.' );
		$this->assertSame( 'Plugin Alpha & Co activated.', $item['message'] );
		$this->assertSame( '2026-09-10T10:00:00', $item['created_gmt'] );
	}

	public function test_a_network_event_has_no_site(): void {
		$this->insert( 'plugin_deactivated', 0, 'alpha/alpha.php', [ 'network' => true ] );

		$items = $this->plugin()->events_query()->list( [ 'type' => [ 'plugin_deactivated' ] ] )['items'];
		$item  = array_values( array_filter( $items, static fn ( array $item ): bool => null === $item['site'] ) )[0];

		$this->assertSame( 'Plugin Alpha & Co network deactivated.', $item['message'] );
	}

	public function test_a_deleted_site_keeps_the_name_and_address_of_the_event(): void {
		// Le cœur enregistre le titre échappé (esc_html) : le nom est décodé en texte brut, l'affichage l'échappera.
		$this->insert( 'site_deleted', 4699, 'example.org/gone/', [ 'name' => 'Gone &amp; forgotten' ] );

		$item = $this->plugin()->events_query()->list( [ 'site' => 4699 ] )['items'][0];

		$this->assertSame( 4699, $item['site']['id'] );
		$this->assertSame( 'Gone & forgotten', $item['site']['name'] );
		$this->assertSame( 'http://example.org/gone/', $item['site']['url'] );
		$this->assertSame( 'Site deleted.', $item['message'] );
	}

	public function test_the_other_events_of_a_deleted_site_show_the_identity_noted_at_its_deletion(): void {
		$this->insert( 'alert_raised', 4698, 'no_users', [], '2026-09-10 10:00:00' );
		$this->insert( 'plugin_activated', 4698, 'alpha/alpha.php', [], '2026-09-10 10:00:01' );
		$this->insert( 'site_deleted', 4698, 'example.org/rh/', [ 'name' => 'Blog RH' ], '2026-09-10 10:00:02' );

		$items = $this->plugin()->events_query()->list( [ 'site' => 4698 ] )['items'];

		$this->assertCount( 3, $items );
		foreach ( $items as $item ) {
			$this->assertSame( 4698, $item['site']['id'] );
			$this->assertSame( 'Blog RH', $item['site']['name'] );
			$this->assertSame( 'http://example.org/rh/', $item['site']['url'] );
			$this->assertSame( '', $item['site']['admin_url'] );
		}
	}

	public function test_a_site_deleted_before_its_first_analysis_keeps_the_name_of_its_creation(): void {
		$this->insert( 'site_created', 4697, 'example.org/old/', [ 'name' => 'Old' ], '2026-09-10 10:00:00' );
		$this->insert( 'plugin_activated', 4697, 'alpha/alpha.php', [], '2026-09-10 10:00:01' );
		$this->insert( 'site_deleted', 4697, 'example.org/old/', [ 'name' => '' ], '2026-09-10 10:00:02' );

		$items = $this->plugin()->events_query()->list( [ 'site' => 4697 ] )['items'];

		$this->assertCount( 3, $items );
		$this->assertSame( [ 'Old', 'Old', 'Old' ], array_column( array_column( $items, 'site' ), 'name' ) );
		$this->assertSame( 'Old', $items[0]['label'], 'The deletion names the site as its label too.' );
	}

	public function test_the_identity_of_another_network_is_not_used(): void {
		$this->insert( 'alert_raised', 4696, 'no_users' );
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => 2,
					'site_id'    => 4696,
					'type'       => 'site_deleted',
					'subject'    => 'other.example.org/',
					'meta'       => [ 'name' => 'Other network' ],
					'created_at' => '2026-09-10 00:00:00',
				],
			]
		);

		$site = $this->plugin()->events_query()->list( [ 'site' => 4696 ] )['items'][0]['site'];

		$this->assertSame( 'Site #4696', $site['name'] );
		$this->assertSame( '', $site['url'] );
	}

	public function test_theme_and_alert_events_read_as_sentences(): void {
		$this->insert( 'theme_switched', 4601, 'msradar-missing-child', [ 'from' => 'msradar-missing-parent' ], '2026-09-10 10:00:01' );
		$this->insert( 'alert_raised', 4601, 'no_users', [ 'severity' => 'error' ], '2026-09-10 10:00:02' );
		$this->insert( 'alert_resolved', 4601, 'acme_unknown_rule', [], '2026-09-10 10:00:03' );

		$this->assertSame(
			[
				'Alert resolved: acme_unknown_rule.',
				'New alert: Site without users.',
				'Theme switched from msradar-missing-parent to msradar-missing-child.',
			],
			array_column( $this->items(), 'message' )
		);
	}

	public function test_filters_since_and_by_type_and_stays_in_the_network(): void {
		$this->insert( 'site_created', 4601, 'example.org/current/', [], '2026-08-01 00:00:00' );
		$this->insert( 'alert_raised', 4601, 'no_users', [], '2026-09-10 00:00:00' );
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => 2,
					'site_id'    => 4601,
					'type'       => 'alert_raised',
					'subject'    => 'no_users',
					'meta'       => [],
					'created_at' => '2026-09-10 00:00:00',
				],
			]
		);

		$this->assertCount( 1, $this->items( [ 'since' => '2026-09-01 00:00:00' ] ) );
		$this->assertSame( [ 'site_created' ], array_column( $this->items( [ 'type' => [ 'site_created' ] ] ), 'type' ) );
		$this->assertSame( 2, $this->plugin()->events_query()->list( [ 'site' => 4601 ] )['total'], 'The event of network 2 is not read.' );
	}
}
