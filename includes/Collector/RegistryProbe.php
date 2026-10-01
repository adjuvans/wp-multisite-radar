<?php
namespace MultisiteRadar\Collector;

use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Relève, dans le contexte du site lui-même, les types de contenu et taxonomies enregistrés
 * (libellé, visibilité, origine) et les stocke dans l'option autoloadée msradar_registry.
 * Ne s'exécute jamais pendant le rendu d'une page publique : uniquement en cron, en admin ou en WP-CLI.
 */
final class RegistryProbe {

	public const OPTION         = 'msradar_registry';
	public const CRON_HOOK      = 'msradar_probe';
	public const MAX_AGE        = WEEK_IN_SECONDS;
	public const STATUS_FRESH   = 'fresh';
	public const STATUS_STALE   = 'stale';
	public const STATUS_MISSING = 'missing';

	private SitesRepository $sites;
	private ?OriginResolver $resolver;
	/** @var array<string, array<string, array{kind: string, slug: string}|null>> */
	private array $origins = [
		'post_type' => [],
		'taxonomy'  => [],
	];
	private bool $tracking = false;

	public function __construct( SitesRepository $sites, ?OriginResolver $resolver = null ) {
		$this->sites    = $sites;
		$this->resolver = $resolver;
	}

	/**
	 * Appelé au chargement du plugin, donc avant les plugins du site.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );

		$cli = defined( 'WP_CLI' ) && WP_CLI;
		$due = self::is_due( get_option( self::OPTION ), Fingerprint::current(), time() );

		if ( $cli || ( $due && ( wp_doing_cron() || is_admin() ) ) ) {
			$this->start_tracking();
		}
		if ( ! $due ) {
			return;
		}
		add_action( 'init', [ $this, 'schedule' ], 100 );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			add_action( 'admin_init', [ $this, 'run' ] );
		}
	}

	public function start_tracking(): void {
		if ( $this->tracking ) {
			return;
		}
		$this->tracking = true;
		add_filter( 'register_post_type_args', [ $this, 'track_post_type' ], 10, 2 );
		add_filter( 'register_taxonomy_args', [ $this, 'track_taxonomy' ], 10, 2 );
	}

	/**
	 * @param mixed $args Arguments d'enregistrement, renvoyés tels quels.
	 * @param mixed $name Nom du type de contenu.
	 * @return mixed
	 */
	public function track_post_type( $args, $name ) {
		$this->origins['post_type'][ (string) $name ] = $this->resolver()->from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- origin detection.
		return $args;
	}

	/**
	 * @param mixed $args Arguments d'enregistrement, renvoyés tels quels.
	 * @param mixed $name Nom de la taxonomie.
	 * @return mixed
	 */
	public function track_taxonomy( $args, $name ) {
		$this->origins['taxonomy'][ (string) $name ] = $this->resolver()->from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- origin detection.
		return $args;
	}

	public function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	public function run(): void {
		// Sans suivi, les origines sont inconnues : ne jamais écraser un bon relevé par des « unknown ».
		if ( ! $this->tracking ) {
			return;
		}
		update_option( self::OPTION, $this->build(), true );
		$this->sites->mark_dirty( [ get_current_blog_id() ] );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function build(): array {
		$post_types = [];
		foreach ( get_post_types( [], 'objects' ) as $name => $object ) {
			$post_types[ (string) $name ] = $this->describe( 'post_type', (string) $name, $object );
		}
		$taxonomies = [];
		foreach ( get_taxonomies( [], 'objects' ) as $name => $object ) {
			$taxonomies[ (string) $name ] = $this->describe( 'taxonomy', (string) $name, $object );
		}

		return [
			'fingerprint' => Fingerprint::current(),
			'built_at'    => time(),
			'post_types'  => $post_types,
			'taxonomies'  => $taxonomies,
		];
	}

	/**
	 * @param mixed $registry Valeur brute de l'option msradar_registry.
	 */
	public static function status( $registry, string $fingerprint, int $now ): string {
		if ( ! is_array( $registry ) || ! isset( $registry['fingerprint'], $registry['built_at'] ) ) {
			return self::STATUS_MISSING;
		}
		if ( $fingerprint !== $registry['fingerprint'] || (int) $registry['built_at'] < $now - self::MAX_AGE ) {
			return self::STATUS_STALE;
		}
		return self::STATUS_FRESH;
	}

	/**
	 * @param mixed $registry Valeur brute de l'option msradar_registry.
	 */
	public static function is_due( $registry, string $fingerprint, int $now ): bool {
		return self::STATUS_FRESH !== self::status( $registry, $fingerprint, $now );
	}

	/**
	 * @param \WP_Post_Type|\WP_Taxonomy $type_object Objet enregistré.
	 */
	private function describe( string $kind, string $name, $type_object ): array {
		$builtin = (bool) $type_object->_builtin;
		if ( $builtin ) {
			$origin = [
				'kind' => 'core',
				'slug' => '',
			];
		} else {
			$origin = $this->origins[ $kind ][ $name ] ?? [
				'kind' => 'unknown',
				'slug' => '',
			];
		}

		return [
			'label'   => (string) $type_object->label,
			'public'  => (bool) $type_object->public,
			'show_ui' => (bool) $type_object->show_ui,
			'builtin' => $builtin,
			'origin'  => $origin,
		];
	}

	private function resolver(): OriginResolver {
		return $this->resolver ??= OriginResolver::from_environment();
	}
}
