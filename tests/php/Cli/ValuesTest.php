<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Values;
use MultisiteRadar\Tests\TestCase;

final class ValuesTest extends TestCase {

	public function test_json_values_keep_their_type(): void {
		$this->assertSame( 14, Values::parse( '14' ) );
		$this->assertTrue( Values::parse( 'true' ) );
		$this->assertFalse( Values::parse( 'false' ) );
		$this->assertNull( Values::parse( 'null' ) );
		$this->assertSame( [ 'post', 'page' ], Values::parse( '["post","page"]' ) );
		$this->assertSame( [ 'enabled' => false ], Values::parse( '{"enabled":false}' ) );
		$this->assertSame( '7', Values::parse( '"7"' ) );
	}

	public function test_anything_else_is_kept_as_text(): void {
		$this->assertSame( 'post', Values::parse( 'post' ) );
		$this->assertSame( '', Values::parse( '' ) );
		$this->assertSame( '[post', Values::parse( '[post' ) );
	}
}
