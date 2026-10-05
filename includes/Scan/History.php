<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SnapshotsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Historique du réseau courant, sur la tâche quotidienne (spec §7.3) : instantané du jour des sites analysés (écart E6),
 * puis purge des instantanés et des événements au-delà de la rétention réglée. Ne fait jamais échouer la tâche.
 */
final class History {

	private SnapshotsRepository $snapshots;
	private EventsRepository $events;
	private Settings $settings;

	public function __construct( SnapshotsRepository $snapshots, EventsRepository $events, Settings $settings ) {
		$this->snapshots = $snapshots;
		$this->events    = $events;
		$this->settings  = $settings;
	}

	public function register(): void {
		// Après Queue::daily() (priorité 10), qui rattache les sites créés et retire les lignes orphelines.
		add_action( Queue::HOOK_DAILY, [ $this, 'daily' ], 20 );
	}

	/**
	 * Non typé : WordPress appelle les hooks avec un argument vide (« »), qu'un paramètre ?int refuserait en PHP 8.
	 *
	 * @param mixed $now Horodatage Unix (tests) ; maintenant sinon.
	 */
	public function daily( $now = null ): void {
		if ( ! Schema::is_current() ) {
			return;
		}
		$now        = is_int( $now ) ? $now : time();
		$network_id = get_current_network_id();
		$snapshots  = max( 1, (int) $this->settings->get( 'retention.snapshots_days', 365 ) );
		$events     = max( 1, (int) $this->settings->get( 'retention.events_days', 90 ) );
		try {
			$this->snapshots->capture( $network_id, gmdate( 'Y-m-d', $now ) );
			$this->snapshots->purge( $network_id, gmdate( 'Y-m-d', $now - $snapshots * DAY_IN_SECONDS ) );
			$this->events->purge( $network_id, gmdate( 'Y-m-d H:i:s', $now - $events * DAY_IN_SECONDS ) );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
		}
	}
}
