<?php
namespace MultisiteRadar;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Cli\RadarCommand;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\SiteUsersQuery;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Rest\AlertsController;
use MultisiteRadar\Rest\PreferencesController;
use MultisiteRadar\Rest\ScanController;
use MultisiteRadar\Rest\SettingsController;
use MultisiteRadar\Rest\SitesController;
use MultisiteRadar\Scan\BatchRunner;
use MultisiteRadar\Scan\Invalidation;
use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\SitesMenu\Module as SitesMenuModule;
use MultisiteRadar\SitesMenu\SitesListCache;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Conteneur : construit les services à la demande et branche les hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private ?Settings $settings = null;

	private ?Preferences $preferences = null;

	private ?SitesRepository $sites = null;

	private ?ExtensionsRepository $extensions = null;

	private ?RegistryProbe $probe = null;

	private ?SiteCollector $collector = null;

	private ?RuleRegistry $rules = null;

	private ?AlertEvaluator $evaluator = null;

	private ?AlertFormatter $formatter = null;

	private ?Lock $lock = null;

	private ?BatchRunner $runner = null;

	private ?Queue $queue = null;

	private ?Invalidation $invalidation = null;

	private ?SitesQuery $sites_query = null;

	private ?SiteUsersQuery $site_users_query = null;

	private ?AlertsQuery $alerts_query = null;

	private ?LegacyMigration $legacy = null;

	private ?ExportHandler $export = null;

	private ?SitesListCache $sites_list_cache = null;

	private ?SitesMenuModule $sites_menu = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'render_multisite_notice' ] );
			return;
		}

		Capabilities::register();
		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
		$this->probe()->register();
		$this->queue()->register();
		$this->invalidation()->register();
		$this->sites_menu()->register();
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		$this->legacy()->register();

		if ( is_admin() ) {
			$this->export()->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			RadarCommand::register( $this );
		}
	}

	public function register_rest_routes(): void {
		Installer::maybe_upgrade();
		$controllers = [
			new SitesController( $this->sites_query(), $this->site_users_query() ),
			new ScanController( $this->sites(), $this->runner(), $this->queue(), $this->lock() ),
			new SettingsController( $this->settings(), $this->rules() ),
			new AlertsController( $this->alerts_query() ),
			new PreferencesController( $this->preferences() ),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	public function render_multisite_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Multisite Radar requires a WordPress Multisite network. It does nothing on a single site.', 'multisite-radar' )
		);
	}

	public function legacy(): LegacyMigration {
		return $this->legacy ??= new LegacyMigration( $this->settings() );
	}

	public function settings(): Settings {
		return $this->settings ??= new Settings();
	}

	public function sites(): SitesRepository {
		return $this->sites ??= new SitesRepository();
	}

	public function probe(): RegistryProbe {
		return $this->probe ??= new RegistryProbe( $this->sites() );
	}

	public function collector(): SiteCollector {
		return $this->collector ??= new SiteCollector( $this->settings() );
	}

	public function rules(): RuleRegistry {
		return $this->rules ??= RuleRegistry::create_default();
	}

	public function evaluator(): AlertEvaluator {
		return $this->evaluator ??= new AlertEvaluator( $this->rules(), $this->settings() );
	}

	public function formatter(): AlertFormatter {
		return $this->formatter ??= new AlertFormatter( $this->rules() );
	}

	public function lock(): Lock {
		return $this->lock ??= new Lock();
	}

	public function runner(): BatchRunner {
		return $this->runner ??= new BatchRunner( $this->sites(), $this->extensions(), $this->collector(), $this->evaluator(), $this->lock() );
	}

	public function extensions(): ExtensionsRepository {
		return $this->extensions ??= new ExtensionsRepository();
	}

	/**
	 * Vide les caches en mémoire des services (utile après une annulation de transaction en test).
	 */
	public function reset_caches(): void {
		if ( null !== $this->settings ) {
			$this->settings->reset_cache();
		}
		if ( null !== $this->evaluator ) {
			$this->evaluator->reset();
		}
		if ( null !== $this->queue ) {
			$this->queue->reset();
		}
	}

	public function queue(): Queue {
		return $this->queue ??= new Queue( $this->runner(), $this->sites(), $this->evaluator(), $this->settings() );
	}

	public function sites_list_cache(): SitesListCache {
		return $this->sites_list_cache ??= new SitesListCache();
	}

	public function sites_menu(): SitesMenuModule {
		return $this->sites_menu ??= new SitesMenuModule( $this->settings(), $this->sites_list_cache() );
	}

	public function invalidation(): Invalidation {
		return $this->invalidation ??= new Invalidation( $this->sites(), $this->extensions(), $this->settings() );
	}

	public function site_users_query(): SiteUsersQuery {
		return $this->site_users_query ??= new SiteUsersQuery();
	}

	public function sites_query(): SitesQuery {
		return $this->sites_query ??= new SitesQuery( $this->sites(), $this->extensions(), $this->formatter(), $this->settings() );
	}

	public function alerts_query(): AlertsQuery {
		return $this->alerts_query ??= new AlertsQuery( $this->sites(), $this->rules(), $this->evaluator(), $this->formatter() );
	}

	public function preferences(): Preferences {
		return $this->preferences ??= new Preferences();
	}

	public function export(): ExportHandler {
		return $this->export ??= new ExportHandler( $this->sites_query() );
	}
}
