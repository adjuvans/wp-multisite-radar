<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Tests\TestCase;

final class FingerprintTest extends TestCase {

	public function test_plugin_order_does_not_matter(): void {
		$a = Fingerprint::compute( [ 'b/b.php', 'a/a.php' ], [ 'n/n.php', 'm/m.php' ], 'child', 'parent', '7.1.2', '2.0.0' );
		$b = Fingerprint::compute( [ 'a/a.php', 'b/b.php' ], [ 'm/m.php', 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' );

		$this->assertSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $a );
	}

	public function test_any_input_changes_the_fingerprint(): void {
		$base     = [ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' ];
		$variants = [
			[ [ 'a/a.php', 'b/b.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [], 'child', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'other', 'parent', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'other', '7.1.2', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.2.0', '2.0.0' ],
			[ [ 'a/a.php' ], [ 'n/n.php' ], 'child', 'parent', '7.1.2', '2.0.1' ],
		];
		foreach ( $variants as $variant ) {
			$this->assertNotSame( Fingerprint::compute( ...$base ), Fingerprint::compute( ...$variant ) );
		}
	}

	public function test_from_raw_normalises_corrupted_values_like_current_does(): void {
		global $wp_version;

		$this->assertSame(
			Fingerprint::compute( [ 'a/a.php', 'b/b.php' ], [ 'n/n.php' ], 'child', '', $wp_version, MSRADAR_VERSION ),
			Fingerprint::from_raw( [ 'b/b.php', 42, 'a/a.php' ], [ 'n/n.php' => 1, 0 => 'stray' ], 'child', [ 'not a string' ] )
		);
		$this->assertSame( Fingerprint::from_raw( [], [], '', '' ), Fingerprint::from_raw( 'corrupt', 'corrupt', null, false ) );
	}

	public function test_current_reads_the_current_site(): void {
		global $wp_version;
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		update_option( 'active_plugins', [ 'acme/acme.php' ] );

		$expected = Fingerprint::compute(
			[ 'acme/acme.php' ],
			array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ),
			(string) get_option( 'stylesheet' ),
			(string) get_option( 'template' ),
			$wp_version,
			MSRADAR_VERSION
		);
		$actual   = Fingerprint::current();
		restore_current_blog();

		$this->assertSame( $expected, $actual );
	}
}
