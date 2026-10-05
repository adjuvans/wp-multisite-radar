<?php
namespace MultisiteRadar\Storage;

use MultisiteRadar\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le SQL de la table msradar_snapshots : une ligne par site analysé et par jour UTC (spec §3.1, lot 3).
 */
final class SnapshotsRepository {

	/**
	 * Instantané des sites analysés du réseau pour un jour : la prise du jour remplace la précédente (écart E6).
	 *
	 * @param string $day Jour UTC « Y-m-d ».
	 * @return int Nombre de sites pris.
	 * @throws \RuntimeException Si une écriture échoue.
	 */
	public function capture( int $network_id, string $day ): int {
		global $wpdb;
		$table = Schema::snapshots_table();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND day = %s', $table, $network_id, $day ) );
		self::check();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (site_id, day, network_id, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count)
				SELECT site_id, %s, network_id, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count
				FROM %i WHERE network_id = %d AND scanned_at IS NOT NULL',
				$table,
				$day,
				Schema::sites_table(),
				$network_id
			)
		);
		self::check();
		return (int) $inserted;
	}

	/**
	 * Une ligne par jour depuis $since_day : sites, contenus, médias, sites par gravité de leur alerte la plus haute.
	 *
	 * @return array<int, array{day: string, sites: int, content_count: int, media_count: int, alerts_error: int, alerts_warning: int, alerts_info: int}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function network_series( int $network_id, string $since_day ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, COUNT(*) AS sites, SUM(content_count) AS content_count, SUM(media_count) AS media_count,
				SUM(alert_level = 3) AS alerts_error, SUM(alert_level = 2) AS alerts_warning, SUM(alert_level = 1) AS alerts_info
				FROM %i WHERE network_id = %d AND day >= %s GROUP BY day ORDER BY day ASC',
				Schema::snapshots_table(),
				$network_id,
				$since_day
			),
			ARRAY_A
		);
		self::check();
		return array_map(
			static function ( array $row ): array {
				return [
					'day'            => (string) $row['day'],
					'sites'          => (int) $row['sites'],
					'content_count'  => (int) $row['content_count'],
					'media_count'    => (int) $row['media_count'],
					'alerts_error'   => (int) $row['alerts_error'],
					'alerts_warning' => (int) $row['alerts_warning'],
					'alerts_info'    => (int) $row['alerts_info'],
				];
			},
			(array) $rows
		);
	}

	/**
	 * @return array<int, array{day: string, users_count: int, content_count: int, media_count: int, disk_bytes: int|null, db_bytes: int|null, alert_level: int, alerts_count: int}>
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function site_series( int $site_id, string $since_day ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, users_count, content_count, media_count, disk_bytes, db_bytes, alert_level, alerts_count FROM %i WHERE site_id = %d AND day >= %s ORDER BY day ASC',
				Schema::snapshots_table(),
				$site_id,
				$since_day
			),
			ARRAY_A
		);
		self::check();
		return array_map(
			static function ( array $row ): array {
				return [
					'day'           => (string) $row['day'],
					'users_count'   => (int) $row['users_count'],
					'content_count' => (int) $row['content_count'],
					'media_count'   => (int) $row['media_count'],
					'disk_bytes'    => null === $row['disk_bytes'] ? null : (int) $row['disk_bytes'],
					'db_bytes'      => null === $row['db_bytes'] ? null : (int) $row['db_bytes'],
					'alert_level'   => (int) $row['alert_level'],
					'alerts_count'  => (int) $row['alerts_count'],
				];
			},
			(array) $rows
		);
	}

	/**
	 * @param string $before_day Jour UTC « Y-m-d » : les jours antérieurs sont supprimés.
	 * @return int Nombre de lignes supprimées.
	 * @throws \RuntimeException Si la suppression échoue.
	 */
	public function purge( int $network_id, string $before_day ): int {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE network_id = %d AND day < %s', Schema::snapshots_table(), $network_id, $before_day ) );
		self::check();
		return (int) $deleted;
	}

	private static function check(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
