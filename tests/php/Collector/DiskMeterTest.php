<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\DiskMeter;
use MultisiteRadar\Tests\TestCase;

final class DiskMeterTest extends TestCase {

	private string $root = '';

	/**
	 * Arborescence factice : 100 + 2000 octets à la racine et dans 2026/09, 500 octets dans sites/2.
	 * Rien ici ne lève d'exception (voir Global Constraints, tests PHP).
	 */
	public function set_up(): void {
		parent::set_up();
		$this->root = untrailingslashit( get_temp_dir() ) . '/msradar-disk-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->root . '/2026/09' );
		wp_mkdir_p( $this->root . '/sites/2' );
		file_put_contents( $this->root . '/a.txt', str_repeat( 'a', 100 ) );
		file_put_contents( $this->root . '/2026/09/b.jpg', str_repeat( 'b', 2000 ) );
		file_put_contents( $this->root . '/sites/2/c.png', str_repeat( 'c', 500 ) );
	}

	public function tear_down(): void {
		self::remove( $this->root );
		parent::tear_down();
	}

	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	public function test_sums_the_files_of_every_sub_folder(): void {
		$this->assertSame(
			[
				'bytes'    => 2600,
				'complete' => true,
			],
			DiskMeter::measure( $this->root, [], 5.0 )
		);
	}

	public function test_excluded_folders_are_not_counted_whatever_their_trailing_slash(): void {
		$this->assertSame(
			[
				'bytes'    => 2100,
				'complete' => true,
			],
			DiskMeter::measure( $this->root . '/', [ $this->root . '/sites/' ], 5.0 )
		);
	}

	public function test_a_missing_folder_weighs_nothing(): void {
		$this->assertSame(
			[
				'bytes'    => 0,
				'complete' => true,
			],
			DiskMeter::measure( $this->root . '/nothing-here', [], 5.0 )
		);
	}

	public function test_a_file_is_not_a_folder_that_can_be_measured(): void {
		$this->assertNull( DiskMeter::measure( $this->root . '/a.txt', [], 5.0 ) );
	}

	public function test_an_exhausted_budget_returns_a_partial_value(): void {
		$result = DiskMeter::measure( $this->root, [], 0.0 );

		$this->assertFalse( $result['complete'] );
		$this->assertLessThan( 2600, $result['bytes'] );
	}

	public function test_symbolic_links_are_never_followed(): void {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root, $this->root . '/2026/loop' ) ) {
			$this->markTestSkipped( 'Symbolic links are not available here.' );
		}
		@symlink( $this->root . '/a.txt', $this->root . '/copy-of-a.txt' );

		$this->assertSame(
			[
				'bytes'    => 2600,
				'complete' => true,
			],
			DiskMeter::measure( $this->root, [], 5.0 ),
			'A link that loops back to the root, or to a file already counted, adds nothing.'
		);
	}
}
