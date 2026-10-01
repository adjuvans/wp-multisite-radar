<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Requêtes REST préchargées dans la page : le client affiche la première vue sans attendre (spec §1.4 n° 2).
 */
final class Preload {

	/**
	 * @param string $view  overview, sites, alerts ou settings.
	 * @param array  $query Paramètres d'URL de la page.
	 * @param array  $prefs Préférences complètes de l'utilisateur.
	 * @return string[]
	 */
	public static function paths( string $view, array $query, array $prefs ): array {
		$paths = [ ViewQuery::path( '/preferences' ) ];
		switch ( $view ) {
			case 'overview':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/scan/status' );
				break;
			case 'sites':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/sites', ViewQuery::sites( $query, $prefs ) );
				$site_id = ViewQuery::site_id( $query );
				if ( $site_id > 0 ) {
					$paths[] = ViewQuery::path( '/sites/' . $site_id );
				}
				break;
			case 'alerts':
				$paths[] = ViewQuery::path( '/alerts/summary' );
				$paths[] = ViewQuery::path( '/alerts', ViewQuery::alerts( $query, $prefs ) );
				break;
			case 'settings':
				$paths[] = ViewQuery::path( '/settings' );
				break;
		}
		return $paths;
	}

	/**
	 * Seules les réponses 200 sont gardées (comportement de rest_preload_api_request()) : une erreur est
	 * redemandée par le client, qui l'affiche avec « Retry ».
	 *
	 * @param string[] $paths
	 * @return array<string, array{body: mixed, headers: array}>
	 */
	public static function run( array $paths ): array {
		return array_reduce( $paths, 'rest_preload_api_request', [] );
	}
}
