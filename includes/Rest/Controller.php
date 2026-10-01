<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Capabilities;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base des contrôleurs : espace de noms et contrôles de capacités.
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'multisite-radar/v1';

	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Les dépôts lèvent une RuntimeException quand une lecture échoue. La réponse devient alors une erreur 500,
	 * plutôt qu'une liste vide que l'interface prendrait pour un réseau sans site.
	 *
	 * @param callable $callback Renvoie la réponse de la route.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function guard( callable $callback ) {
		try {
			return $callback();
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', static::class, $error );
			return new WP_Error( 'msradar_storage_error', __( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * Réponse de collection avec les en-têtes de pagination du cœur.
	 */
	protected function paginated( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}
}
