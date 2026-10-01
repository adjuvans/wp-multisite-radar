<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Tests\TestCase;

final class ExtensionsRepositoryTest extends TestCase {

	public function test_replace_for_site_stores_local_plugins_and_theme_roles(): void {
		$extensions = $this->plugin()->extensions();

		$extensions->replace_for_site( 5, [ 'hello.php', 'acme/acme.php', 'acme/acme.php' ], 'child', 'parent' );

		$this->assertSame(
			[
				[ 'type' => 'plugin', 'slug' => 'acme/acme.php', 'role' => 'local' ],
				[ 'type' => 'plugin', 'slug' => 'hello.php', 'role' => 'local' ],
				[ 'type' => 'theme', 'slug' => 'child', 'role' => 'active' ],
				[ 'type' => 'theme', 'slug' => 'parent', 'role' => 'parent' ],
			],
			$extensions->for_site( 5 )
		);

		$extensions->replace_for_site( 5, [], 'twentytwentyfive', 'twentytwentyfive' );
		$this->assertSame( [ [ 'type' => 'theme', 'slug' => 'twentytwentyfive', 'role' => 'active' ] ], $extensions->for_site( 5 ) );

		$extensions->delete_for_site( 5 );
		$this->assertSame( [], $extensions->for_site( 5 ) );
	}
}
