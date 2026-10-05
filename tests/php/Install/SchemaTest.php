<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class SchemaTest extends TestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::sites_table() ) );
		foreach ( [ 'site_id', 'network_id', 'alert_level', 'alert_rules', 'registry_status', 'data', 'dirty', 'dirty_since', 'scanned_at' ] as $column ) {
			$this->assertContains( $column, $columns );
		}

		$this->assertSame(
			[ 'site_id', 'type', 'slug', 'role' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::extensions_table() ) )
		);
		$this->assertTrue( Schema::is_current() );
	}

	public function test_install_is_idempotent(): void {
		$this->assertTrue( Schema::install() );
		$this->assertTrue( Schema::install() );

		$this->assertTrue( Schema::is_current() );
	}

	public function test_the_current_version_has_the_siteurl_column(): void {
		global $wpdb;

		$this->assertTrue( Schema::install() );

		$this->assertSame( 4, Schema::VERSION );
		$this->assertSame( 4, (int) get_site_option( Schema::OPTION ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Schema::sites_table(), 'siteurl' ) ) );
	}

	public function test_version_4_adds_the_history_tables(): void {
		global $wpdb;

		$this->assertSame(
			[ 'id', 'network_id', 'site_id', 'type', 'subject', 'meta', 'created_at' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::events_table() ) )
		);
		$this->assertSame(
			[ 'site_id', 'day', 'network_id', 'users_count', 'content_count', 'media_count', 'disk_bytes', 'db_bytes', 'alert_level', 'alerts_count' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::snapshots_table() ) )
		);
		$this->assertContains( Schema::events_table(), Schema::tables() );
		$this->assertContains( Schema::snapshots_table(), Schema::tables() );
	}
}
