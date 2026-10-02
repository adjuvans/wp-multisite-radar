<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\ThemesQuery;
use MultisiteRadar\Tests\TestCase;

final class ThemesQueryTest extends TestCase {

	/**
	 * @var string[]
	 */
	private array $directories = [];

	public function set_up(): void {
		global $wp_theme_directories;
		parent::set_up();
		$this->directories = $wp_theme_directories;
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		search_theme_directories( true );

		update_site_option( 'allowedthemes', [ 'msradar-fixture-child' => true ] );
		set_site_transient(
			'update_themes',
			(object) [
				'response' => [
					'msradar-fixture-theme' => [
						'theme'       => 'msradar-fixture-theme',
						'new_version' => '1.1.0',
					],
				],
			]
		);
		$this->make_record(
			961,
			[
				'name'             => 'Child site',
				'theme_stylesheet' => 'msradar-fixture-child',
				'theme_template'   => 'msradar-fixture-theme',
			]
		);
		$this->make_record(
			962,
			[
				'name'             => 'Parent site',
				'theme_stylesheet' => 'msradar-fixture-theme',
				'theme_template'   => 'msradar-fixture-theme',
			]
		);
		$this->make_record(
			963,
			[
				'network_id'       => 2,
				'theme_stylesheet' => 'msradar-fixture-spare',
				'theme_template'   => 'msradar-fixture-spare',
			]
		);
		$this->make_record(
			964,
			[
				'name'             => 'Orphan site',
				'theme_stylesheet' => 'msradar-gone-child',
				'theme_template'   => 'msradar-gone',
			]
		);
		$this->make_record( 965 );
	}

	public function tear_down(): void {
		global $wp_theme_directories;
		$wp_theme_directories = $this->directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the test's own change.
		delete_site_transient( 'theme_roots' );
		search_theme_directories( true );
		parent::tear_down();
	}

	private function query(): ThemesQuery {
		return $this->plugin()->themes_query();
	}

	/**
	 * @return array<string, array>
	 */
	private function themes(): array {
		$themes = [];
		foreach ( $this->query()->all() as $theme ) {
			$themes[ $theme['id'] ] = $theme;
		}
		return $themes;
	}

	public function test_counts_active_themes_and_parents_of_this_network(): void {
		$themes = $this->themes();

		$parent = $themes['msradar-fixture-theme'];
		$this->assertSame( [ 'id', 'stylesheet', 'name', 'version', 'installed', 'parent', 'allowed_on_network', 'active_count', 'parent_count', 'sites_count', 'status', 'update_version' ], array_keys( $parent ) );
		$this->assertSame( 'R&D Studio', $parent['name'] );
		$this->assertSame( [ 'used', 1, 1, 2, '1.1.0', false, null ], [ $parent['status'], $parent['active_count'], $parent['parent_count'], $parent['sites_count'], $parent['update_version'], $parent['allowed_on_network'], $parent['parent'] ] );

		$child = $themes['msradar-fixture-child'];
		$this->assertSame( [ 'used', 1, 0, 1, true, 'msradar-fixture-theme' ], [ $child['status'], $child['active_count'], $child['parent_count'], $child['sites_count'], $child['allowed_on_network'], $child['parent'] ] );

		$spare = $themes['msradar-fixture-spare'];
		$this->assertSame( [ 'unused', 0 ], [ $spare['status'], $spare['sites_count'] ], 'Used on another network only.' );
	}

	public function test_a_missing_parent_is_reported_as_not_installed(): void {
		$themes = $this->themes();

		$this->assertSame( [ 'missing', false, 0, 1, 'msradar-gone' ], [ $themes['msradar-gone']['status'], $themes['msradar-gone']['installed'], $themes['msradar-gone']['active_count'], $themes['msradar-gone']['parent_count'], $themes['msradar-gone']['name'] ] );
		$this->assertSame( [ 'missing', 1 ], [ $themes['msradar-gone-child']['status'], $themes['msradar-gone-child']['active_count'] ] );
	}

	public function test_filters_and_find(): void {
		$ids = static fn ( array $result ): array => array_values( wp_list_pluck( $result['items'], 'id' ) );

		$this->assertSame( [ 'msradar-fixture-child' ], $ids( $this->query()->list( [ 'search' => 'fixture child' ] ) ) );
		$this->assertSame( [ 'msradar-gone', 'msradar-gone-child' ], $ids( $this->query()->list( [ 'status' => [ 'missing' ] ] ) ) );
		$this->assertSame( [ 'msradar-fixture-theme' ], $ids( $this->query()->list( [ 'has_update' => true ] ) ) );
		$this->assertContains( 'msradar-fixture-spare', $ids( $this->query()->list( [ 'status' => [ 'unused' ], 'per_page' => 100 ] ) ) );
		$this->assertSame( 'msradar-fixture-theme', $this->query()->find( 'msradar-fixture-theme' )['stylesheet'] );
		$this->assertNull( $this->query()->find( 'nope' ) );
	}

	public function test_summary_counts_this_network(): void {
		$summary = $this->query()->summary();

		$this->assertSame( [ 'installed', 'unused', 'missing', 'updates' ], array_keys( $summary ) );
		$this->assertSame( 2, $summary['missing'] );
		$this->assertSame( 1, $summary['updates'] );
		$this->assertGreaterThanOrEqual( 3, $summary['installed'] );
	}
}
