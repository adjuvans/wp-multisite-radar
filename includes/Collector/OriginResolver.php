<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Déduit l'origine (plugin, mu-plugin, thème) d'un fichier PHP ou d'une pile d'appels.
 */
final class OriginResolver {

	private string $plugin_dir;
	private string $mu_plugin_dir;
	/** @var string[] */
	private array $theme_dirs;
	private string $self_dir;
	/** @var array<string, string> Chemin réel => chemin vu par WordPress (plugins en symlink). */
	private array $path_aliases = [];

	/**
	 * @param string[]              $theme_dirs   Racines de thèmes.
	 * @param array<string, string> $path_aliases Chemin réel => chemin sous WP_PLUGIN_DIR.
	 */
	public function __construct( string $plugin_dir, string $mu_plugin_dir, array $theme_dirs, string $self_dir, array $path_aliases = [] ) {
		$this->plugin_dir    = self::dir( $plugin_dir );
		$this->mu_plugin_dir = self::dir( $mu_plugin_dir );
		$this->theme_dirs    = array_map( [ self::class, 'dir' ], array_values( $theme_dirs ) );
		$this->self_dir      = self::dir( $self_dir );
		foreach ( $path_aliases as $real => $alias ) {
			$this->path_aliases[ self::dir( (string) $real ) ] = self::dir( (string) $alias );
		}
	}

	public static function from_environment(): self {
		global $wp_theme_directories, $wp_plugin_paths;
		$theme_dirs = is_array( $wp_theme_directories ) && [] !== $wp_theme_directories ? $wp_theme_directories : [ get_theme_root() ];
		$aliases    = [];
		foreach ( (array) $wp_plugin_paths as $dir => $real_dir ) {
			$aliases[ (string) $real_dir ] = (string) $dir;
		}
		return new self( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, $theme_dirs, MSRADAR_DIR, $aliases );
	}

	/**
	 * @return array{kind: string, slug: string}|null
	 */
	public function resolve_file( string $file ): ?array {
		$file = wp_normalize_path( $file );
		if ( 0 === strpos( $file, $this->self_dir ) ) {
			return null;
		}
		foreach ( $this->path_aliases as $real => $alias ) {
			if ( 0 === strpos( $file, $real ) ) {
				$file = $alias . substr( $file, strlen( $real ) );
				break;
			}
		}
		if ( 0 === strpos( $file, $this->self_dir ) ) {
			return null;
		}
		if ( 0 === strpos( $file, $this->mu_plugin_dir ) ) {
			return self::origin( 'mu-plugin', substr( $file, strlen( $this->mu_plugin_dir ) ) );
		}
		if ( 0 === strpos( $file, $this->plugin_dir ) ) {
			return self::origin( 'plugin', substr( $file, strlen( $this->plugin_dir ) ) );
		}
		foreach ( $this->theme_dirs as $theme_dir ) {
			if ( 0 === strpos( $file, $theme_dir ) ) {
				return self::origin( 'theme', substr( $file, strlen( $theme_dir ) ) );
			}
		}
		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $frames Résultat de debug_backtrace().
	 * @return array{kind: string, slug: string}|null
	 */
	public function from_backtrace( array $frames ): ?array {
		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}
			$origin = $this->resolve_file( $frame['file'] );
			if ( null !== $origin ) {
				return $origin;
			}
		}
		return null;
	}

	/**
	 * @return array{kind: string, slug: string}
	 */
	private static function origin( string $kind, string $relative ): array {
		$parts = explode( '/', ltrim( $relative, '/' ) );
		$slug  = 1 === count( $parts ) ? (string) preg_replace( '/\.php$/', '', $parts[0] ) : $parts[0];
		return [
			'kind' => $kind,
			'slug' => $slug,
		];
	}

	private static function dir( string $dir ): string {
		return trailingslashit( wp_normalize_path( $dir ) );
	}
}
