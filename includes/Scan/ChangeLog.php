<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Journal des changements du réseau (spec §7.3). Compare l'état d'un site avant et après une analyse ou un recalcul
 * des alertes, et note les événements isolés (création ou suppression d'un site, extension activée sur le réseau).
 * Une écriture qui échoue est signalée à msradar_error : le journal ne fait jamais échouer une analyse.
 */
final class ChangeLog {

	private EventsRepository $events;

	public function __construct( EventsRepository $events ) {
		$this->events = $events;
	}

	public function compare( ?SiteRecord $before, SiteRecord $after ): void {
		$this->write( self::diff( $before, $after, current_time( 'mysql', true ) ) );
	}

	/**
	 * @param int    $site_id 0 pour un événement du réseau entier (écart E4).
	 * @param string $type    Un des EventsRepository::TYPES.
	 * @param array  $meta    Métadonnées (JSON).
	 */
	public function record( int $network_id, int $site_id, string $type, string $subject, array $meta = [] ): void {
		$this->write(
			[
				[
					'network_id' => $network_id,
					'site_id'    => $site_id,
					'type'       => $type,
					'subject'    => $subject,
					'meta'       => $meta,
					'created_at' => current_time( 'mysql', true ),
				],
			]
		);
	}

	/**
	 * Événements entre deux états d'un site : plugins activés ou désactivés sur le site, thème changé, alertes
	 * apparues ou résolues. Tant que l'état précédent n'a jamais été analysé, rien n'est noté (écart E3).
	 *
	 * @return array<int, array{network_id: int, site_id: int, type: string, subject: string, meta: array, created_at: string}>
	 */
	public static function diff( ?SiteRecord $before, SiteRecord $after, string $now_gmt ): array {
		if ( null === $before || null === $before->scanned_at ) {
			return [];
		}
		$events = [];
		$add    = static function ( string $type, string $subject, array $meta = [] ) use ( $after, $now_gmt, &$events ): void {
			$events[] = [
				'network_id' => $after->network_id,
				'site_id'    => $after->site_id,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => $meta,
				'created_at' => $now_gmt,
			];
		};

		// Les plugins du site (option brute) évitent de noter une fausse (dés)activation quand un plugin passe
		// de local à réseau ; sans cette clé d'un côté (relevé 2.0.0-beta.5), on compare les plugins locaux des deux côtés.
		$key         = isset( $before->data['plugins_site'], $after->data['plugins_site'] ) ? 'plugins_site' : 'plugins_local';
		$old_plugins = self::plugins( $before, $key );
		$new_plugins = self::plugins( $after, $key );
		foreach ( array_diff( $new_plugins, $old_plugins ) as $file ) {
			$add( 'plugin_activated', $file );
		}
		foreach ( array_diff( $old_plugins, $new_plugins ) as $file ) {
			$add( 'plugin_deactivated', $file );
		}
		if ( '' !== $after->theme_stylesheet && $after->theme_stylesheet !== $before->theme_stylesheet ) {
			$add( 'theme_switched', $after->theme_stylesheet, [ 'from' => $before->theme_stylesheet ] );
		}

		$old_alerts = self::alerts( $before );
		$new_alerts = self::alerts( $after );
		foreach ( array_diff_key( $new_alerts, $old_alerts ) as $rule => $severity ) {
			$add( 'alert_raised', (string) $rule, [ 'severity' => $severity ] );
		}
		foreach ( array_diff_key( $old_alerts, $new_alerts ) as $rule => $severity ) {
			$add( 'alert_resolved', (string) $rule, [ 'severity' => $severity ] );
		}
		return $events;
	}

	/**
	 * @param string $key Clé de `data` à lire : `plugins_site` ou `plugins_local`.
	 * @return string[] Fichiers des plugins activés sur le site.
	 */
	private static function plugins( SiteRecord $record, string $key ): array {
		return array_values( array_unique( array_filter( array_map( 'strval', (array) ( $record->data[ $key ] ?? [] ) ) ) ) );
	}

	/**
	 * @return array<string, string> Règle => gravité.
	 */
	private static function alerts( SiteRecord $record ): array {
		$alerts = [];
		foreach ( (array) ( $record->data['alerts'] ?? [] ) as $alert ) {
			if ( is_array( $alert ) && isset( $alert['rule'] ) ) {
				$alerts[ (string) $alert['rule'] ] = (string) ( $alert['severity'] ?? '' );
			}
		}
		return $alerts;
	}

	/**
	 * @param array[] $events
	 */
	private function write( array $events ): void {
		if ( [] === $events ) {
			return;
		}
		try {
			$this->events->insert( $events );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', self::class, $error );
		}
	}
}
