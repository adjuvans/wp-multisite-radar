<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Colonnes exportables des sites : clés du résumé REST, aplaties en valeurs scalaires.
 */
final class SitesColumns {

	/**
	 * @return array<string, string> Clé => en-tête traduit, dans l'ordre d'export.
	 */
	public static function all(): array {
		return [
			'id'                => __( 'ID', 'multisite-radar' ),
			'name'              => __( 'Name', 'multisite-radar' ),
			'url'               => __( 'URL', 'multisite-radar' ),
			'admin_url'         => __( 'Admin URL', 'multisite-radar' ),
			'status'            => __( 'Status', 'multisite-radar' ),
			'theme'             => __( 'Theme', 'multisite-radar' ),
			'users_count'       => __( 'Users', 'multisite-radar' ),
			'admins_count'      => __( 'Administrators', 'multisite-radar' ),
			'content_count'     => __( 'Published content', 'multisite-radar' ),
			'media_count'       => __( 'Media', 'multisite-radar' ),
			'disk_bytes'        => __( 'Disk usage (bytes)', 'multisite-radar' ),
			'db_bytes'          => __( 'Database size (bytes)', 'multisite-radar' ),
			'autoload_bytes'    => __( 'Autoloaded options (bytes)', 'multisite-radar' ),
			'last_activity_gmt' => __( 'Last activity (UTC)', 'multisite-radar' ),
			'alert_level'       => __( 'Alert level', 'multisite-radar' ),
			'alerts_count'      => __( 'Alerts', 'multisite-radar' ),
			'alert_rules'       => __( 'Alert rules', 'multisite-radar' ),
			'registry_status'   => __( 'Content types status', 'multisite-radar' ),
			'scanned_at_gmt'    => __( 'Analysed (UTC)', 'multisite-radar' ),
		];
	}

	/**
	 * @param array    $item Site mis en forme par SitesQuery::summary().
	 * @param string[] $keys Colonnes à produire.
	 * @return array<string, mixed> Valeurs scalaires ou null, dans l'ordre de $keys.
	 */
	public static function row( array $item, array $keys ): array {
		$flat = [
			'status'      => self::status( (array) ( $item['status'] ?? [] ) ),
			'theme'       => (string) ( $item['theme']['stylesheet'] ?? '' ),
			'alert_rules' => implode( ',', (array) ( $item['alert_rules'] ?? [] ) ),
		];
		$row  = [];
		foreach ( $keys as $key ) {
			$row[ $key ] = array_key_exists( $key, $flat ) ? $flat[ $key ] : ( $item[ $key ] ?? null );
		}
		return $row;
	}

	private static function status( array $status ): string {
		$flags = [ ! empty( $status['public'] ) ? 'public' : 'private' ];
		foreach ( [ 'archived', 'spam', 'deleted' ] as $flag ) {
			if ( ! empty( $status[ $flag ] ) ) {
				$flags[] = $flag;
			}
		}
		return implode( ',', $flags );
	}
}
