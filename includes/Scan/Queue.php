<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Planification sur le site principal de chaque réseau, qui ne traite que ses propres sites, avec ses propres réglages :
 * - traitement de la file toutes les 5 minutes, avec relance immédiate tant qu'il reste des sites ;
 * - passage quotidien : sites manquants, lignes orphelines, analyse complète périodique ;
 * - recalcul des alertes par lots bornés et reprenables, planifié chaque jour et après un changement de réglages.
 */
final class Queue {

	public const HOOK_PROCESS     = 'msradar_process_queue';
	public const HOOK_CONTINUE    = 'msradar_process_queue_continue';
	public const HOOK_DAILY       = 'msradar_daily';
	public const HOOK_RECOMPUTE   = 'msradar_recompute_alerts';
	public const SCHEDULE         = 'msradar_five_minutes';
	public const LAST_FULL_SCAN   = 'msradar_last_full_scan';
	public const RECOMPUTE_CURSOR = 'msradar_recompute_cursor';
	private const RECOMPUTE_CHUNK = 200;

	private BatchRunner $runner;
	private SitesRepository $sites;
	private AlertEvaluator $evaluator;
	private Settings $settings;
	private ChangeLog $changes;
	private bool $processed = false;

	public function __construct( BatchRunner $runner, SitesRepository $sites, AlertEvaluator $evaluator, Settings $settings, ChangeLog $changes ) {
		$this->runner    = $runner;
		$this->sites     = $sites;
		$this->evaluator = $evaluator;
		$this->settings  = $settings;
		$this->changes   = $changes;
	}

	public function reset(): void {
		$this->processed = false;
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
		add_action( self::HOOK_PROCESS, [ $this, 'process' ] );
		add_action( self::HOOK_CONTINUE, [ $this, 'process' ] );
		add_action( self::HOOK_DAILY, [ $this, 'daily' ] );
		add_action( self::HOOK_RECOMPUTE, [ $this, 'run_recompute' ] );
		add_action( 'msradar_activated', [ $this, 'schedule' ] );
		add_action( 'msradar_deactivated', [ $this, 'unschedule' ] );
		add_action( 'msradar_upgraded', [ $this, 'on_upgraded' ], 10, 2 );
		add_action( 'msradar_settings_updated', [ $this, 'on_settings_updated' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * @param mixed $schedules Intervalles cron existants.
	 * @return array
	 */
	public function add_schedule( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : [];
		$schedules[ self::SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			// Un plugin tiers peut planifier un événement avant init : ne pas charger les traductions trop tôt.
			'display'  => did_action( 'init' ) ? __( 'Every five minutes (Multisite Radar)', 'multisite-radar' ) : 'Every five minutes (Multisite Radar)',
		];
		return $schedules;
	}

	public function schedule(): void {
		MainSite::run(
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK_PROCESS ) ) {
					wp_schedule_event( time(), self::SCHEDULE, self::HOOK_PROCESS );
				}
				if ( false === wp_next_scheduled( self::HOOK_DAILY ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
				}
			}
		);
	}

	public function ensure_scheduled(): void {
		if ( is_main_site() ) {
			$this->schedule();
		}
	}

	public function unschedule(): void {
		MainSite::run(
			static function (): void {
				foreach ( [ self::HOOK_PROCESS, self::HOOK_CONTINUE, self::HOOK_DAILY, self::HOOK_RECOMPUTE ] as $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		);
	}

	/**
	 * Une seule passe de travail (file ou recalcul) par requête : wp-cron exécute ensemble tous les événements échus.
	 */
	public function process(): void {
		if ( $this->processed ) {
			$this->continue_soon();
			return;
		}
		if ( ! self::schema_ready() ) {
			return;
		}
		$this->processed = true;
		$result          = $this->runner->run( BatchRunner::default_budget() );
		if ( ! $result['locked'] && $result['remaining'] > 0 ) {
			$this->continue_soon();
		}
	}

	public function continue_soon(): void {
		MainSite::schedule_once( self::HOOK_CONTINUE );
	}

	public function request_full_scan( int $network_id ): void {
		$this->sites->seed_from_blogs( $network_id );
		$this->sites->mark_all_dirty( $network_id );
		update_site_option( self::LAST_FULL_SCAN, time() );
		$this->continue_soon();
	}

	public function daily(): void {
		$network_id = get_current_network_id();
		$this->sites->seed_from_blogs( $network_id );
		$this->sites->delete_orphans();

		$days = max( 1, (int) $this->settings->get( 'scan.full_rescan_days', 7 ) );
		if ( (int) get_site_option( self::LAST_FULL_SCAN, 0 ) < time() - $days * DAY_IN_SECONDS ) {
			$this->request_full_scan( $network_id );
		} elseif ( $this->sites->count_dirty( $network_id ) > 0 ) {
			$this->continue_soon();
		}

		// Planifié plutôt qu'exécuté ici. Si wp-cron le lance dans la même requête qu'un passage de file, run_recompute() se reporte.
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
	}

	/**
	 * Recalcule les alertes des sites déjà analysés du réseau courant (avec ses réglages), à partir des données stockées.
	 *
	 * Le parcours reprend après le curseur msradar_recompute_cursor si celui-ci a été écrit avec les mêmes réglages
	 * d'alertes. Sinon (réglages modifiés pendant un recalcul, ou curseur entier de la 2.0.0-alpha.1), il repart du
	 * premier site. Dès que le budget est écoulé (au moins un site est évalué par appel) ou que le verrou est perdu,
	 * la position est enregistrée et la suite est planifiée ; à la fin du parcours, le curseur est supprimé.
	 * Seuls les sites dont les alertes changent sont réécrits.
	 *
	 * @param Lock|null  $lock   Verrou détenu à rafraîchir entre deux lots.
	 * @param float|null $budget Secondes disponibles ; BatchRunner::default_budget() par défaut.
	 * @return int Nombre de sites évalués pendant cet appel, que leurs alertes aient changé (et été réécrites) ou non.
	 * @throws \RuntimeException Si la lecture des sites échoue.
	 */
	public function recompute_alerts( ?Lock $lock = null, ?float $budget = null ): int {
		$network_id = get_current_network_id();
		$budget     = $budget ?? BatchRunner::default_budget();
		$start      = microtime( true );
		$now        = time();
		$config     = $this->alerts_config_hash();
		$cursor     = get_site_option( self::RECOMPUTE_CURSOR, false );
		$after      = is_array( $cursor ) && ( $cursor['config'] ?? null ) === $config ? max( 0, (int) ( $cursor['after'] ?? 0 ) ) : 0;
		$count      = 0;
		while ( true ) {
			$ids = $this->sites->ids_after( $after, self::RECOMPUTE_CHUNK, $network_id );
			foreach ( $this->sites->find_many( $ids ) as $site_id => $record ) {
				if ( null !== $record->scanned_at ) {
					if ( $count > 0 && microtime( true ) - $start >= $budget ) {
						$this->pause_recompute( $after, $config );
						return $count;
					}
					$this->recompute_site( $record, $now );
					++$count;
				}
				$after = $site_id;
			}
			if ( count( $ids ) < self::RECOMPUTE_CHUNK ) {
				delete_site_option( self::RECOMPUTE_CURSOR );
				return $count;
			}
			$after = (int) end( $ids );
			if ( null !== $lock && ! $lock->refresh() ) {
				$this->pause_recompute( $after, $config );
				return $count;
			}
		}
	}

	private function recompute_site( SiteRecord $record, int $now ): void {
		$previous = clone $record;
		$before   = [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ];
		$this->evaluator->apply( $record, $now );
		if ( [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ] !== $before ) {
			// Un changement n'est noté que s'il est enregistré : sinon le même événement reviendrait à chaque recalcul.
			if ( $this->sites->save_alerts( $record ) ) {
				$this->changes->compare( $previous, $record );
			}
		}
	}

	/**
	 * Empreinte des réglages d'alertes et de l'état du réseau avec lesquels un parcours a été calculé : un curseur
	 * écrit sous un autre état (par exemple pendant que NetworkStateWatcher::check() le supprimait) repart du
	 * premier site.
	 */
	private function alerts_config_hash(): string {
		return md5( (string) wp_json_encode( [ $this->settings->get( 'alerts', [] ), get_site_option( NetworkStateWatcher::OPTION, '' ) ] ) );
	}

	private function pause_recompute( int $after, string $config ): void {
		update_site_option(
			self::RECOMPUTE_CURSOR,
			[
				'after'  => $after,
				'config' => $config,
			]
		);
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
	}

	public function run_recompute(): void {
		if ( $this->processed ) {
			// Un passage de file a déjà consommé le budget de cette requête cron.
			MainSite::schedule_once( self::HOOK_RECOMPUTE, MINUTE_IN_SECONDS );
			return;
		}
		if ( ! self::schema_ready() ) {
			return; // La mise à niveau relancera une analyse complète, qui réévalue les alertes de chaque site.
		}
		$this->processed = true;
		try {
			$done = $this->runner->locked(
				function ( Lock $lock ): void {
					$this->recompute_alerts( $lock );
				}
			);
		} catch ( \RuntimeException $error ) {
			// Lecture en échec : le recalcul reprendra à son curseur.
			do_action( 'msradar_error', __METHOD__, $error );
			$done = false;
		}
		if ( ! $done ) {
			MainSite::schedule_once( self::HOOK_RECOMPUTE, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Après une mise à jour du plugin sans visite de l'administration (mise à jour automatique, wp plugin update), le
	 * cron peut passer avant admin_init : le schéma est d'abord mis à niveau ; s'il ne peut pas l'être, la passe attend
	 * plutôt que d'écrire dans des tables périmées.
	 */
	private static function schema_ready(): bool {
		Installer::maybe_upgrade();
		return Schema::is_current();
	}

	/**
	 * Les colonnes ajoutées jusqu'à la version 3 du schéma ne se remplissent qu'à l'analyse : tout le réseau courant
	 * est alors marqué. La version 4 n'ajoute que les tables de l'historique : rien à réanalyser (écart E2 du plan M6).
	 *
	 * @param mixed $version  Version de schéma installée.
	 * @param mixed $previous Version avant la mise à niveau (0 : inconnue ou première installation).
	 */
	public function on_upgraded( $version = 0, $previous = 0 ): void {
		$this->schedule();
		if ( (int) $previous < 3 ) {
			$this->request_full_scan( get_current_network_id() );
		}
	}

	/**
	 * Les nouveaux réglages s'appliquent à tout le réseau : le recalcul repart du premier site.
	 * La date de dernière activité dépend des types d'activité, et les mesures du réglage « mesurer le disque » :
	 * si l'un d'eux change, tous les sites sont réanalysés.
	 *
	 * @param mixed $new_settings Réglages complets après la mise à jour.
	 * @param mixed $old_settings Réglages complets avant la mise à jour.
	 */
	public function on_settings_updated( $new_settings = [], $old_settings = [] ): void {
		$this->evaluator->reset();
		delete_site_option( self::RECOMPUTE_CURSOR );
		MainSite::schedule_once( self::HOOK_RECOMPUTE );

		if ( self::activity_types( $new_settings ) !== self::activity_types( $old_settings )
			|| self::measures_disk( $new_settings ) !== self::measures_disk( $old_settings ) ) {
			$this->sites->mark_all_dirty( get_current_network_id() );
			$this->continue_soon();
		}
	}

	/**
	 * @param mixed $settings Réglages complets.
	 * @return string[] Types d'activité triés (l'ordre de saisie ne compte pas).
	 */
	private static function activity_types( $settings ): array {
		$types = is_array( $settings ) ? (array) ( $settings['scan']['activity_post_types'] ?? [] ) : [];
		$types = array_values( array_unique( array_map( 'strval', $types ) ) );
		sort( $types );
		return $types;
	}

	/**
	 * @param mixed $settings Réglages complets.
	 */
	private static function measures_disk( $settings ): bool {
		return is_array( $settings ) ? (bool) ( $settings['scan']['measure_disk'] ?? true ) : true;
	}

	public static function next_run(): ?int {
		return MainSite::run(
			static function (): ?int {
				$next = wp_next_scheduled( self::HOOK_PROCESS );
				return false === $next ? null : (int) $next;
			}
		);
	}
}
