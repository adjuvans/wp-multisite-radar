<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Storage\SnapshotsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Séries temporelles des instantanés (spec §7.3) : réseau courant, ou un site de ce réseau. Un point par jour
 * d'instantané, sans jours comblés (écart E9).
 */
final class TrendsQuery {

	public const DEFAULT_DAYS = 90;
	public const MAX_DAYS     = 3650;

	private SnapshotsRepository $snapshots;
	private SitesRepository $sites;

	public function __construct( SnapshotsRepository $snapshots, SitesRepository $sites ) {
		$this->snapshots = $snapshots;
		$this->sites     = $sites;
	}

	/**
	 * @return array{days: int, since: string, site: null, points: array[]}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function network( int $days, ?int $now = null ): array {
		$days  = self::bounded( $days );
		$since = self::since( $days, $now );
		return [
			'days'   => $days,
			'since'  => $since,
			'site'   => null,
			'points' => $this->snapshots->network_series( get_current_network_id(), $since ),
		];
	}

	/**
	 * @return array{days: int, since: string, site: int, points: array[]}|null Null si le site n'appartient pas au réseau courant.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function site( int $site_id, int $days, ?int $now = null ): ?array {
		$record = $this->sites->find( $site_id );
		if ( null === $record || get_current_network_id() !== $record->network_id ) {
			return null;
		}
		$days  = self::bounded( $days );
		$since = self::since( $days, $now );
		return [
			'days'   => $days,
			'since'  => $since,
			'site'   => $site_id,
			'points' => array_map(
				static function ( array $point ): array {
					$point['alert_level'] = Severity::name( $point['alert_level'] );
					return $point;
				},
				$this->snapshots->site_series( $site_id, $since )
			),
		];
	}

	private static function bounded( int $days ): int {
		return min( self::MAX_DAYS, max( 2, $days ) );
	}

	/**
	 * Premier jour UTC de la période : aujourd'hui compte pour un jour.
	 */
	private static function since( int $days, ?int $now ): string {
		return gmdate( 'Y-m-d', ( $now ?? time() ) - ( $days - 1 ) * DAY_IN_SECONDS );
	}
}
