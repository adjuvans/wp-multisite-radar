<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Planification sur le site principal :
 * - traitement de la file toutes les 5 minutes, avec relance immédiate tant qu'il reste des sites ;
 * - passage quotidien : sites manquants, lignes orphelines, analyse complète périodique, recalcul des alertes.
 */
final class Queue {

	public const HOOK_PROCESS     = 'msradar_process_queue';
	public const HOOK_CONTINUE    = 'msradar_process_queue_continue';
	public const HOOK_DAILY       = 'msradar_daily';
	public const HOOK_RECOMPUTE   = 'msradar_recompute_alerts';
	public const SCHEDULE         = 'msradar_five_minutes';
	public const LAST_FULL_SCAN   = 'msradar_last_full_scan';
	private const RECOMPUTE_CHUNK = 200;

	private BatchRunner $runner;
	private SitesRepository $sites;
	private AlertEvaluator $evaluator;
	private Settings $settings;

	public function __construct( BatchRunner $runner, SitesRepository $sites, AlertEvaluator $evaluator, Settings $settings ) {
		$this->runner    = $runner;
		$this->sites     = $sites;
		$this->evaluator = $evaluator;
		$this->settings  = $settings;
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

	public function process(): void {
		$result = $this->runner->run( BatchRunner::default_budget() );
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
		} elseif ( $this->sites->count_dirty() > 0 ) {
			$this->continue_soon();
		}

		$this->recompute_alerts();
	}

	/**
	 * Recalcule les alertes de tous les sites déjà analysés, à partir des données stockées.
	 *
	 * @return int Nombre de sites recalculés.
	 */
	public function recompute_alerts(): int {
		$now   = time();
		$after = 0;
		$count = 0;
		do {
			$ids = $this->sites->ids_after( $after, self::RECOMPUTE_CHUNK );
			foreach ( $this->sites->find_many( $ids ) as $record ) {
				if ( null === $record->scanned_at ) {
					continue;
				}
				$this->evaluator->apply( $record, $now );
				$this->sites->save_alerts( $record );
				++$count;
			}
			if ( [] !== $ids ) {
				$after = (int) end( $ids );
			}
			$fetched = count( $ids );
		} while ( self::RECOMPUTE_CHUNK === $fetched );

		return $count;
	}

	public function run_recompute(): void {
		$this->recompute_alerts();
	}

	public function on_settings_updated(): void {
		$this->evaluator->reset();
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
