<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
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
	private bool $processed = false;

	public function __construct( BatchRunner $runner, SitesRepository $sites, AlertEvaluator $evaluator, Settings $settings ) {
		$this->runner    = $runner;
		$this->sites     = $sites;
		$this->evaluator = $evaluator;
		$this->settings  = $settings;
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
		add_action( 'msradar_settings_updated', [ $this, 'on_settings_updated' ] );
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
			'display'  => __( 'Every five minutes (Multisite Radar)', 'multisite-radar' ),
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
	 * Une seule passe par requête : wp-cron exécute tous les événements échus dans la même requête.
	 */
	public function process(): void {
		if ( $this->processed ) {
			$this->continue_soon();
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

		// Jamais dans la même requête qu'un passage de file : wp-cron exécute tous les événements échus ensemble.
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
	}

	/**
	 * Recalcule les alertes des sites déjà analysés du réseau courant (avec ses réglages), à partir des données stockées.
	 *
	 * Le parcours reprend après le curseur msradar_recompute_cursor. Dès que le budget est écoulé (au moins un site
	 * est évalué par appel) ou que le verrou est perdu, la position est enregistrée et la suite est planifiée ;
	 * à la fin du parcours, le curseur est supprimé. Seuls les sites dont les alertes changent sont réécrits.
	 *
	 * @param Lock|null  $lock   Verrou détenu à rafraîchir entre deux lots.
	 * @param float|null $budget Secondes disponibles ; BatchRunner::default_budget() par défaut.
	 * @return int Nombre de sites évalués pendant cet appel, que leurs alertes aient changé (et été réécrites) ou non.
	 */
	public function recompute_alerts( ?Lock $lock = null, ?float $budget = null ): int {
		$network_id = get_current_network_id();
		$budget     = $budget ?? BatchRunner::default_budget();
		$start      = microtime( true );
		$now        = time();
		$after      = max( 0, (int) get_site_option( self::RECOMPUTE_CURSOR, 0 ) );
		$count      = 0;
		while ( true ) {
			$ids = $this->sites->ids_after( $after, self::RECOMPUTE_CHUNK, $network_id );
			foreach ( $this->sites->find_many( $ids ) as $site_id => $record ) {
				if ( null !== $record->scanned_at ) {
					if ( $count > 0 && microtime( true ) - $start >= $budget ) {
						$this->pause_recompute( $after );
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
				$this->pause_recompute( $after );
				return $count;
			}
		}
	}

	private function recompute_site( SiteRecord $record, int $now ): void {
		$before = [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ];
		$this->evaluator->apply( $record, $now );
		if ( [ $record->alert_level, $record->alert_rules, $record->data['alerts'] ?? null ] !== $before ) {
			$this->sites->save_alerts( $record );
		}
	}

	private function pause_recompute( int $after ): void {
		update_site_option( self::RECOMPUTE_CURSOR, $after );
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
	}

	public function run_recompute(): void {
		$done = $this->runner->locked(
			function ( Lock $lock ): void {
				$this->recompute_alerts( $lock );
			}
		);
		if ( ! $done ) {
			MainSite::schedule_once( self::HOOK_RECOMPUTE, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Les nouveaux réglages s'appliquent à tout le réseau : le recalcul repart du premier site.
	 */
	public function on_settings_updated(): void {
		$this->evaluator->reset();
		delete_site_option( self::RECOMPUTE_CURSOR );
		MainSite::schedule_once( self::HOOK_RECOMPUTE );
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
