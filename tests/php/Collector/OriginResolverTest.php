<?php
namespace MultisiteRadar\Tests\Collector;

use MultisiteRadar\Collector\OriginResolver;
use MultisiteRadar\Tests\TestCase;

final class OriginResolverTest extends TestCase {

	private OriginResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new OriginResolver(
			'/srv/wp/wp-content/plugins',
			'/srv/wp/wp-content/mu-plugins',
			[ '/srv/wp/wp-content/themes', '/srv/extra-themes/' ],
			'/srv/wp/wp-content/plugins/multisite-radar',
			[ '/srv/real/acme' => '/srv/wp/wp-content/plugins/acme' ]
		);
	}

	/**
	 * @dataProvider files
	 */
	public function test_resolve_file( string $file, ?array $expected ): void {
		$this->assertSame( $expected, $this->resolver->resolve_file( $file ) );
	}

	public static function files(): array {
		return [
			'plugin in a folder'       => [ '/srv/wp/wp-content/plugins/events/inc/cpt.php', [ 'kind' => 'plugin', 'slug' => 'events' ] ],
			'single-file plugin'       => [ '/srv/wp/wp-content/plugins/hello.php', [ 'kind' => 'plugin', 'slug' => 'hello' ] ],
			'single-file mu-plugin'    => [ '/srv/wp/wp-content/mu-plugins/loader.php', [ 'kind' => 'mu-plugin', 'slug' => 'loader' ] ],
			'mu-plugin in a folder'    => [ '/srv/wp/wp-content/mu-plugins/acme-core/x.php', [ 'kind' => 'mu-plugin', 'slug' => 'acme-core' ] ],
			'theme in a second root'   => [ '/srv/extra-themes/child/functions.php', [ 'kind' => 'theme', 'slug' => 'child' ] ],
			'symlinked plugin'         => [ '/srv/real/acme/acme.php', [ 'kind' => 'plugin', 'slug' => 'acme' ] ],
			'this plugin is ignored'   => [ '/srv/wp/wp-content/plugins/multisite-radar/includes/Plugin.php', null ],
			'core file'                => [ '/srv/wp/wp-includes/post.php', null ],
		];
	}

	public function test_from_backtrace_returns_the_first_third_party_frame(): void {
		$frames = [
			[ 'file' => '/srv/wp/wp-includes/post.php' ],
			[ 'function' => 'closure' ],
			[ 'file' => '/srv/wp/wp-content/plugins/multisite-radar/includes/Collector/RegistryProbe.php' ],
			[ 'file' => '/srv/wp/wp-content/plugins/events/events.php' ],
			[ 'file' => '/srv/wp/wp-content/themes/child/functions.php' ],
		];

		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'events' ], $this->resolver->from_backtrace( $frames ) );
		$this->assertNull( $this->resolver->from_backtrace( [ [ 'file' => '/srv/wp/wp-settings.php' ] ] ) );
	}

	public function test_from_environment_uses_wordpress_directories(): void {
		$resolver = OriginResolver::from_environment();

		$this->assertSame( [ 'kind' => 'plugin', 'slug' => 'akismet' ], $resolver->resolve_file( WP_PLUGIN_DIR . '/akismet/akismet.php' ) );
		$this->assertSame( [ 'kind' => 'theme', 'slug' => 'twentytwentyfive' ], $resolver->resolve_file( get_theme_root() . '/twentytwentyfive/functions.php' ) );
	}

	public function test_realpath_aliases_extracts_symlinks(): void {
		$temp_dir      = get_temp_dir();
		$real_dir      = rtrim( $temp_dir, '/' ) . '/real-dir-' . wp_generate_uuid4();
		$symlink_dir   = rtrim( $temp_dir, '/' ) . '/symlink-dir-' . wp_generate_uuid4();
		$regular_dir   = rtrim( $temp_dir, '/' ) . '/regular-dir-' . wp_generate_uuid4();
		$nonexistent   = rtrim( $temp_dir, '/' ) . '/nonexistent-' . wp_generate_uuid4();

		wp_mkdir_p( $real_dir );
		wp_mkdir_p( $regular_dir );
		symlink( $real_dir, $symlink_dir );

		$paths   = [ $real_dir, $symlink_dir, $regular_dir, $nonexistent ];
		$aliases = OriginResolver::realpath_aliases( $paths );

		$this->assertArrayHasKey( trailingslashit( wp_normalize_path( $real_dir ) ), $aliases );
		$this->assertSame( trailingslashit( wp_normalize_path( $symlink_dir ) ), $aliases[ trailingslashit( wp_normalize_path( $real_dir ) ) ] );
		$this->assertCount( 1, $aliases );

		// Cleanup.
		@unlink( $symlink_dir );
		@rmdir( $real_dir );
		@rmdir( $regular_dir );
	}

	public function test_resolve_symlinked_theme(): void {
		$resolver = new OriginResolver(
			'/srv/wp/wp-content/plugins',
			'/srv/wp/wp-content/mu-plugins',
			[ '/srv/wp/wp-content/themes' ],
			'/srv/wp/wp-content/plugins/multisite-radar',
			[ '/src/my-theme' => '/srv/wp/wp-content/themes/my-theme' ]
		);

		$this->assertSame( [ 'kind' => 'theme', 'slug' => 'my-theme' ], $resolver->resolve_file( '/src/my-theme/functions.php' ) );
	}
}
