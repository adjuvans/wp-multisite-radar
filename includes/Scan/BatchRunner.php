<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Collector\SiteCollector;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Analyse les sites marqués, par lots bornés dans le temps et en mémoire, sous verrou.
 */
final class BatchRunner {

	public const MAX_BUDGET = 20.0;
	private const CHUNK     = 10;

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private SiteCollector $collector;
	private AlertEvaluator $evaluator;
	private Lock $lock;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, SiteCollector $collector, AlertEvaluator $evaluator, Lock $lock ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->collector  = $collector;
		$this->evaluator  = $evaluator;
		$this->lock       = $lock;
	}

	/**
	 * La moitié de max_execution_time, plafonnée à 20 s (20 s si illimité).
	 */
	public static function default_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? min( self::MAX_BUDGET, $max / 2 ) : self::MAX_BUDGET;
	}

	/**
	 * Exécute un callback sous le verrou d'analyse.
	 *
	 * @param callable $callback Appelé avec le Lock (à rafraîchir pour les longs traitements).
	 * @return bool False si le verrou est détenu par un autre processus (callback non appelé).
	 */
	public function locked( callable $callback ): bool {
		if ( ! $this->lock->acquire() ) {
			return false;
		}
		try {
			$callback( $this->lock );
		} finally {
			$this->lock->release();
		}
		return true;
	}

	/**
	 * Traite les sites marqués du réseau courant uniquement : la collecte et les alertes
	 * utilisent les réglages de ce réseau. Le verrou, lui, est commun à tous les réseaux.
	 *
	 * @param float         $budget  Secondes disponibles ; au moins un site est toujours traité.
	 * @param callable|null $on_site Appelé après chaque site avec ( int $site_id, bool $ok ).
	 * @return array{processed: int, remaining: int, locked: bool} remaining : sites encore marqués sur le réseau courant.
	 */
	public function run( float $budget, ?callable $on_site = null ): array {
		$network_id = get_current_network_id();
		if ( ! $this->lock->acquire() ) {
			return [
				'processed' => 0,
				'remaining' => $this->sites->count_dirty( $network_id ),
				'locked'    => true,
			];
		}

		$start     = microtime( true );
		$processed = 0;
		$seen      = [];
		try {
			while ( true ) {
				$ids = array_values( array_diff( $this->sites->next_dirty( self::CHUNK, $network_id ), $seen ) );
				if ( [] === $ids ) {
					break;
				}
				foreach ( $ids as $site_id ) {
					if ( $processed > 0 && ( microtime( true ) - $start >= $budget || $this->memory_exhausted() ) ) {
						break 2;
					}
					$seen[] = $site_id;
					$ok     = $this->scan_site( $site_id );
					++$processed;
					$owned = $this->lock->refresh();
					if ( null !== $on_site ) {
						$on_site( $site_id, $ok );
					}
					if ( ! $owned ) {
						break 2;
					}
				}
			}
		} finally {
			$this->lock->release();
		}

		return [
			'processed' => $processed,
			'remaining' => $this->sites->count_dirty( $network_id ),
			'locked'    => false,
		];
	}

	/**
	 * Le marquage est retiré avant la collecte : un nouveau marquage posé pendant l'analyse
	 * est conservé, car save() n'écrase jamais dirty sur une ligne existante.
	 */
	public function scan_site( int $site_id ): bool {
		$this->sites->clear_dirty( $site_id );

		try {
			$record = $this->collector->collect( $site_id );

			if ( null === $record ) {
				$this->sites->delete( $site_id );
				$this->extensions->delete_for_site( $site_id );
				return false;
			}

			$this->evaluator->apply( $record, time() );
			$this->extensions->replace_for_site(
				$site_id,
				(array) ( $record->data['plugins_local'] ?? [] ),
				$record->theme_stylesheet,
				$record->theme_template
			);
			$this->sites->save( $record );
		} catch ( Throwable $error ) {
			if ( null === get_site( $site_id ) ) {
				$this->sites->delete( $site_id );
				$this->extensions->delete_for_site( $site_id );
				return false;
			}
			$this->record_failure( $site_id, $error->getMessage() );
			return false;
		}

		do_action( 'msradar_site_scanned', $site_id, $record );
		return true;
	}

	private function record_failure( int $site_id, string $message ): void {
		$record = $this->sites->find( $site_id );
		if ( null === $record ) {
			$site               = get_site( $site_id );
			$record             = new SiteRecord();
			$record->site_id    = $site_id;
			$record->network_id = null !== $site ? (int) $site->site_id : get_current_network_id();
			$record->url        = null !== $site ? $site->domain . $site->path : '';
		}
		$record->data['scan_error'] = [
			'message' => $message,
			'at_gmt'  => current_time( 'mysql', true ),
		];
		$record->dirty              = false;
		$record->dirty_since        = null;
		$this->sites->save( $record );
	}

	private function memory_exhausted(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && memory_get_usage( true ) >= 0.8 * $limit;
	}
}
