<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * État de l'analyse du réseau courant : sites, file, verrou, dernière analyse complète, prochain passage du cron.
 * Partagé par la route GET /scan/status et l'ability multisite-radar/network-summary.
 */
final class ScanStatusQuery {

	private SitesRepository $sites;
	private Lock $lock;

	public function __construct( SitesRepository $sites, Lock $lock ) {
		$this->sites = $sites;
		$this->lock  = $lock;
	}

	/**
	 * @return array{total: int, remaining: int, pending: int, locked: bool, last_full_scan_gmt: string|null, next_run_gmt: string|null}
	 */
	public function status(): array {
		$network_id = get_current_network_id();
		$last       = (int) get_site_option( Queue::LAST_FULL_SCAN, 0 );
		$next       = Queue::next_run();
		return [
			'total'              => $this->sites->count_all( $network_id ),
			'remaining'          => $this->sites->count_dirty( $network_id ),
			'pending'            => $this->sites->count_pending( $network_id ),
			'locked'             => $this->lock->is_locked(),
			'last_full_scan_gmt' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s', $last ) : null,
			'next_run_gmt'       => null !== $next ? gmdate( 'Y-m-d\TH:i:s', $next ) : null,
		];
	}
}
