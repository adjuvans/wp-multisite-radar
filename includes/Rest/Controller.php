<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\InventoryList;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
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

	/**
	 * Une page des sites qui utilisent un plugin ou un thème, triés par nom.
	 *
	 * @param array $filter [ 'plugin' => fichier ] ou [ 'theme' => dossier ].
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	protected function sites_page( SitesQuery $sites, array $filter, WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = $sites->list(
			array_merge(
				$filter,
				[
					'page'     => (int) $request['page'],
					'per_page' => $per_page,
					'search'   => (string) $request['search'],
					'orderby'  => 'name',
					'order'    => 'asc',
				]
			)
		);
		return $this->paginated( $result['items'], $result['total'], $per_page );
	}

	/**
	 * Arguments de la liste des sites d'un plugin ou d'un thème.
	 */
	protected static function sites_page_params(): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'search'   => [
				'type'    => 'string',
				'default' => '',
			],
		];
	}

	/**
	 * Arguments communs des listes d'inventaire (plugins, thèmes).
	 *
	 * @param string[] $statuses Statuts possibles.
	 */
	protected static function inventory_params( array $statuses ): array {
		return array_merge(
			self::sites_page_params(),
			[
				'status'     => [
					'type'    => 'array',
					'default' => [],
					'items'   => [
						'type' => 'string',
						'enum' => $statuses,
					],
				],
				'has_update' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'orderby'    => [
					'type'    => 'string',
					'default' => 'name',
					'enum'    => InventoryList::ORDERBY,
				],
				'order'      => [
					'type'    => 'string',
					'default' => 'asc',
					'enum'    => [ 'asc', 'desc' ],
				],
			]
		);
	}

	/**
	 * Arguments d'une requête d'inventaire, tels que PluginsQuery::list() et ThemesQuery::list() les attendent.
	 */
	protected static function inventory_args( WP_REST_Request $request ): array {
		return [
			'page'       => (int) $request['page'],
			'per_page'   => (int) $request['per_page'],
			'search'     => (string) $request['search'],
			'status'     => (array) $request['status'],
			'has_update' => (bool) $request['has_update'],
			'orderby'    => (string) $request['orderby'],
			'order'      => (string) $request['order'],
		];
	}
}
