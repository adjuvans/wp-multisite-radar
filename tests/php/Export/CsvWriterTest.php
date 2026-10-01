<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\CsvWriter;
use MultisiteRadar\Tests\TestCase;

final class CsvWriterTest extends TestCase {

	public function test_writes_a_bom_a_header_and_rfc_4180_rows(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$csv    = new CsvWriter( $stream );

		$csv->header( [ 'ID', 'Name' ] );
		$csv->row(
			[
				'id'   => 12,
				'name' => 'Blog "RH", Paris',
			]
		);
		$csv->row(
			[
				'id'   => null,
				'name' => true,
			]
		);
		rewind( $stream );

		$this->assertSame( "\xEF\xBB\xBFID,Name\n12,\"Blog \"\"RH\"\", Paris\"\n,1\n", stream_get_contents( $stream ) );
	}

	/**
	 * @dataProvider formulas
	 */
	public function test_cells_that_a_spreadsheet_would_run_as_formulas_are_neutralised( string $value, string $expected ): void {
		$this->assertSame( $expected, CsvWriter::cell( $value ) );
	}

	public static function formulas(): array {
		return [
			'equals'       => [ '=HYPERLINK("http://evil.test","x")', '\'=HYPERLINK("http://evil.test","x")' ],
			'plus'         => [ '+33 blog', "'+33 blog" ],
			'minus'        => [ '-cmd', "'-cmd" ],
			'at'           => [ '@SUM(A1)', "'@SUM(A1)" ],
			'tab'          => [ "\tdata", "'\tdata" ],
			'negative int' => [ '-12', '-12' ],
			'plain'        => [ 'Blog RH', 'Blog RH' ],
			'empty'        => [ '', '' ],
		];
	}
}
