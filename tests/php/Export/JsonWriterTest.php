<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\JsonWriter;
use MultisiteRadar\Tests\TestCase;

final class JsonWriterTest extends TestCase {

	public function test_streams_a_single_valid_document(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$json   = new JsonWriter( $stream );

		$json->begin( [ 'filters' => (object) [] ] );
		$json->item( [ 'id' => 1, 'name' => 'Été / <b>' ] );
		$json->item( [ 'id' => 2, 'name' => null ] );
		$json->end();
		rewind( $stream );
		$raw = (string) stream_get_contents( $stream );

		$this->assertStringContainsString( '"filters":{}', $raw );
		$this->assertSame(
			[
				'meta'  => [ 'filters' => [] ],
				'items' => [
					[ 'id' => 1, 'name' => 'Été / <b>' ],
					[ 'id' => 2, 'name' => null ],
				],
			],
			json_decode( $raw, true )
		);
	}

	public function test_an_empty_export_is_still_valid_json(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$json   = new JsonWriter( $stream );

		$json->begin( [] );
		$json->end();
		rewind( $stream );

		$this->assertSame( [ 'meta' => [], 'items' => [] ], json_decode( (string) stream_get_contents( $stream ), true ) );
	}
}
