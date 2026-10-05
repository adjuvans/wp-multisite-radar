<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Requêtes REST préchargées dans la page : le client affiche la première vue sans attendre (spec §1.4 n° 2).
 */
final class Preload {

	/**
	 * @param string $view  overview, sites, plugins, themes, users, alerts, reports ou settings.
	 * @param array  $query Paramètres d'URL de la page.
	 * @param array  $prefs Préférences complètes de l'utilisateur.
	 * @return string[]
	 */
	public static function paths( string $view, array $query, array $prefs ): array {
		$paths = [ ViewQuery::path( '/preferences' ) ];
		switch ( $view ) {
			case 'overview':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/inventory/summary' );
				$paths[] = ViewQuery::path( '/scan/status' );
				$paths[] = ViewQuery::path(
					'/events',
					[
						'page'     => 1,
						'per_page' => 5,
					]
				);
				$paths[] = ViewQuery::path( '/reports/trends', [ 'days' => 30 ] );
				break;
			case 'sites':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/sites', ViewQuery::sites( $query, $prefs ) );
				$site_id = ViewQuery::site_id( $query );
				if ( $site_id > 0 ) {
					$paths[] = ViewQuery::path( '/sites/' . $site_id );
				}
				break;
			case 'plugins':
				$paths[] = ViewQuery::path( '/inventory/summary' );
				$paths[] = ViewQuery::path( '/plugins', ViewQuery::plugins( $query, $prefs ) );
				break;
			case 'themes':
				$paths[] = ViewQuery::path( '/inventory/summary' );
				$paths[] = ViewQuery::path( '/themes', ViewQuery::themes( $query, $prefs ) );
				break;
			case 'users':
				$paths[] = ViewQuery::path( '/users', ViewQuery::users( $query, $prefs ) );
				break;
			case 'alerts':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/alerts', ViewQuery::alerts( $query, $prefs ) );
				break;
			case 'reports':
				$paths[] = ViewQuery::path( '/reports/trends', [ 'days' => 90 ] );
				$paths[] = ViewQuery::path(
					'/events',
					[
						'page'     => 1,
						'per_page' => 20,
					]
				);
				// Lu seulement avec msradar_manage : une réponse 403 n'est pas gardée (Preload::run()).
				$paths[] = ViewQuery::path( '/settings' );
				break;
			case 'settings':
				$paths[] = ViewQuery::path( '/settings' );
				$paths[] = ViewQuery::path( '/alert-rules' );
				break;
		}
		return $paths;
	}

	/**
	 * Seules les réponses 200 sont gardées (comportement de rest_preload_api_request()) : une erreur est
	 * redemandée par le client, qui l'affiche avec « Retry ». Il en va de même d'un chemin dont la route, ou un
	 * filtre tiers, lève une exception : elle est signalée par msradar_error au lieu de casser toute la page.
	 *
	 * @param string[] $paths
	 * @return array<string, array{body: mixed, headers: array}>
	 */
	public static function run( array $paths ): array {
		$preload = [];
		foreach ( $paths as $path ) {
			try {
				$preload = rest_preload_api_request( $preload, $path );
			} catch ( \Throwable $error ) {
				do_action( 'msradar_error', __METHOD__, $error );
			}
		}
		return $preload;
	}
}
