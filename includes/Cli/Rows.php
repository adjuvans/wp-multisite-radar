<?php
namespace MultisiteRadar\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Lignes à plat pour WP_CLI\Utils\format_items() : une valeur scalaire par colonne, les listes jointes par des virgules.
 */
final class Rows {

	/**
	 * Un site de SitesQuery::summary().
	 */
	public static function site( array $item ): array {
		$item['theme']       = (string) ( $item['theme']['stylesheet'] ?? '' );
		$item['status']      = implode( ',', array_keys( array_filter( (array) $item['status'] ) ) );
		$item['alert_rules'] = implode( ',', (array) $item['alert_rules'] );
		return $item;
	}

	/**
	 * Une paire site × règle de AlertsQuery::list().
	 *
	 * @return array{site_id: int, site: string, url: string, rule: string, label: string, severity: string, message: string}
	 */
	public static function alert( array $item ): array {
		return [
			'site_id'  => (int) $item['site']['id'],
			'site'     => (string) $item['site']['name'],
			'url'      => (string) $item['site']['url'],
			'rule'     => (string) $item['rule'],
			'label'    => (string) $item['label'],
			'severity' => (string) $item['severity'],
			'message'  => (string) $item['message'],
		];
	}
}
