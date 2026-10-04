<?php
namespace MultisiteRadar;

// Avant les imports : Plugin Check ne cherche ce garde que dans les 50 premières lignes du fichier.
defined( 'ABSPATH' ) || exit;

use MultisiteRadar\Abilities\FindExtensionUsageAbility;
use MultisiteRadar\Abilities\GetSiteAbility;
use MultisiteRadar\Abilities\ListAlertsAbility;
use MultisiteRadar\Abilities\ListSitesAbility;
use MultisiteRadar\Abilities\NetworkSummaryAbility;
use MultisiteRadar\Abilities\Registrar;
use MultisiteRadar\Admin\Assets;
use MultisiteRadar\Admin\Footer;
use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Admin\Privacy;
use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Cli\RadarCommand;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Export\PluginsExport;
use MultisiteRadar\Export\SitesExport;
use MultisiteRadar\Export\ThemesExport;
use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryQuery;
use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\ScanStatusQuery;
use MultisiteRadar\Query\SiteUsersQuery;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use MultisiteRadar\Query\UsersQuery;
use MultisiteRadar\Rest\AlertRulesController;
use MultisiteRadar\Rest\AlertsController;
use MultisiteRadar\Rest\InventoryController;
use MultisiteRadar\Rest\PluginsController;
use MultisiteRadar\Rest\PreferencesController;
use MultisiteRadar\Rest\ScanController;
use MultisiteRadar\Rest\SettingsController;
use MultisiteRadar\Rest\SitesController;
use MultisiteRadar\Rest\ThemesController;
use MultisiteRadar\Rest\UsersController;
use MultisiteRadar\Scan\BatchRunner;
use MultisiteRadar\Scan\Invalidation;
use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\NetworkStateWatcher;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Settings\SettingsUpdater;
use MultisiteRadar\SitesMenu\Module as SitesMenuModule;
use MultisiteRadar\SitesMenu\SitesListCache;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Storage\UsersRepository;

/**
 * Conteneur : construit les services à la demande et branche les hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private ?Registrar $abilities = null;

	private ?Settings $settings = null;

	private ?SettingsUpdater $settings_updater = null;

	private ?Preferences $preferences = null;

	private ?SitesRepository $sites = null;

	private ?ExtensionsRepository $extensions = null;

	private ?RegistryProbe $probe = null;

	private ?SiteCollector $collector = null;

	private ?NetworkState $network_state = null;

	private ?RuleRegistry $rules = null;

	private ?AlertEvaluator $evaluator = null;

	private ?AlertFormatter $formatter = null;

	private ?Lock $lock = null;

	private ?BatchRunner $runner = null;

	private ?Queue $queue = null;

	private ?Invalidation $invalidation         = null;
	private ?NetworkStateWatcher $state_watcher = null;

	private ?SitesQuery $sites_query = null;

	private ?ScanStatusQuery $scan_status_query = null;

	private ?SiteUsersQuery $site_users_query = null;

	private ?AlertsQuery $alerts_query = null;

	private ?PluginsQuery $plugins_query = null;

	private ?ThemesQuery $themes_query = null;

	private ?InventoryQuery $inventory_query = null;

	private ?UsersRepository $users_repository = null;

	private ?UsersQuery $users_query = null;

	private ?LegacyMigration $legacy = null;

	private ?ExportHandler $export = null;

	private ?Menu $admin_menu = null;

	private ?Assets $assets = null;

	private ?Footer $footer = null;

	private ?Privacy $privacy = null;

	private ?SitesListCache $sites_list_cache = null;

	private ?SitesMenuModule $sites_menu = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'render_multisite_notice' ] );
			return;
		}

		Capabilities::register();
		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
		$this->probe()->register();
		$this->queue()->register();
		$this->invalidation()->register();
		$this->state_watcher()->register();
		$this->sites_menu()->register();
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		$this->legacy()->register();

		if ( is_admin() ) {
			$this->export()->register();
			$this->admin_menu()->register();
			$this->assets()->register();
			$this->footer()->register();
			$this->privacy()->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			RadarCommand::register( $this );
		}
	}

	/**
	 * Traductions livrées dans languages/ (générées par « make i18n ») ; un paquet de langue de WordPress.org,
	 * s'il existe, reste prioritaire.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'multisite-radar', false, dirname( plugin_basename( MSRADAR_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- the plugin ships its own translations.
	}

	public function register_rest_routes(): void {
		Installer::maybe_upgrade();
		$controllers = [
			new SitesController( $this->sites_query(), $this->site_users_query() ),
			new PluginsController( $this->plugins_query(), $this->sites_query() ),
			new ThemesController( $this->themes_query(), $this->sites_query() ),
			new UsersController( $this->users_query() ),
			new InventoryController( $this->inventory_query() ),
			new ScanController( $this->sites(), $this->runner(), $this->queue(), $this->scan_status_query() ),
			new SettingsController( $this->settings(), $this->settings_updater() ),
			new AlertsController( $this->alerts_query() ),
			new AlertRulesController( $this->rules() ),
			new PreferencesController( $this->preferences() ),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	public function register_ability_category(): void {
		$this->abilities()->register_category();
	}

	public function register_abilities(): void {
		$this->abilities()->register_abilities();
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

	public function settings_updater(): SettingsUpdater {
		return $this->settings_updater ??= new SettingsUpdater( $this->settings(), $this->rules() );
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

	public function network_state(): NetworkState {
		return $this->network_state ??= new NetworkState();
	}

	public function rules(): RuleRegistry {
		return $this->rules ??= RuleRegistry::create_default( $this->network_state() );
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
		if ( null !== $this->network_state ) {
			$this->network_state->reset();
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

	public function state_watcher(): NetworkStateWatcher {
		return $this->state_watcher ??= new NetworkStateWatcher( $this->network_state() );
	}

	public function site_users_query(): SiteUsersQuery {
		return $this->site_users_query ??= new SiteUsersQuery();
	}

	public function sites_query(): SitesQuery {
		return $this->sites_query ??= new SitesQuery( $this->sites(), $this->extensions(), $this->formatter(), $this->settings() );
	}

	public function scan_status_query(): ScanStatusQuery {
		return $this->scan_status_query ??= new ScanStatusQuery( $this->sites(), $this->lock() );
	}

	/**
	 * Construit pendant wp_abilities_api_init, après init : jamais au démarrage du plugin.
	 */
	public function abilities(): Registrar {
		return $this->abilities ??= new Registrar(
			$this->settings(),
			[
				new NetworkSummaryAbility( $this->scan_status_query(), $this->alerts_query(), $this->inventory_query() ),
				new ListSitesAbility( $this->sites_query() ),
				new GetSiteAbility( $this->sites_query() ),
				new FindExtensionUsageAbility( $this->plugins_query(), $this->themes_query(), $this->sites_query() ),
				new ListAlertsAbility( $this->alerts_query(), $this->rules() ),
			]
		);
	}

	public function alerts_query(): AlertsQuery {
		return $this->alerts_query ??= new AlertsQuery( $this->sites(), $this->rules(), $this->evaluator(), $this->formatter() );
	}

	public function plugins_query(): PluginsQuery {
		return $this->plugins_query ??= new PluginsQuery( $this->extensions(), $this->sites() );
	}

	public function themes_query(): ThemesQuery {
		return $this->themes_query ??= new ThemesQuery( $this->sites() );
	}

	public function inventory_query(): InventoryQuery {
		return $this->inventory_query ??= new InventoryQuery( $this->plugins_query(), $this->themes_query(), $this->sites() );
	}

	public function users_repository(): UsersRepository {
		return $this->users_repository ??= new UsersRepository();
	}

	public function users_query(): UsersQuery {
		return $this->users_query ??= new UsersQuery( $this->users_repository() );
	}

	public function preferences(): Preferences {
		return $this->preferences ??= new Preferences();
	}

	public function export(): ExportHandler {
		return $this->export ??= new ExportHandler(
			[
				'sites'   => new SitesExport( $this->sites_query() ),
				'plugins' => new PluginsExport( $this->plugins_query() ),
				'themes'  => new ThemesExport( $this->themes_query() ),
			]
		);
	}

	public function admin_menu(): Menu {
		return $this->admin_menu ??= new Menu();
	}

	public function assets(): Assets {
		return $this->assets ??= new Assets( $this->admin_menu(), $this->preferences(), MSRADAR_DIR . 'build/', MSRADAR_URL . 'build/' );
	}

	public function footer(): Footer {
		return $this->footer ??= new Footer( $this->admin_menu(), MSRADAR_DIR . 'build/', MSRADAR_URL . 'build/' );
	}

	public function privacy(): Privacy {
		return $this->privacy ??= new Privacy();
	}
}
