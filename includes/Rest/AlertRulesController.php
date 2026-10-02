<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /alert-rules : définitions des règles d'alertes, dont la page Réglages construit son formulaire (spec §5.1).
 * Les réglages effectifs de chaque règle restent dans GET /settings (écart E10 du plan M4).
 */
final class AlertRulesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'alert-rules';

	private RuleRegistry $rules;

	public function __construct( RuleRegistry $rules ) {
		$this->rules = $rules;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$items = [];
		foreach ( $this->rules->all() as $rule ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $rule, $request ) );
		}
		return new WP_REST_Response( $items );
	}

	/**
	 * @param RuleInterface   $item    Règle.
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$schema = $item->params_schema();
		if ( isset( $schema['properties'] ) && [] === $schema['properties'] ) {
			$schema['properties'] = new \stdClass(); // {} en JSON, comme tout objet du schéma.
		}
		$params = $item->default_params();

		return new WP_REST_Response(
			[
				'id'               => $item->id(),
				'label'            => $item->label(),
				'description'      => $item->description(),
				'default_severity' => $item->default_severity(),
				'default_params'   => [] === $params ? new \stdClass() : $params,
				'params_schema'    => $schema,
			]
		);
	}

	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$readonly     = static fn ( array $schema ): array => array_merge( $schema, [ 'readonly' => true ] );
		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'msradar-alert-rule',
			'type'       => 'object',
			'properties' => [
				'id'               => $readonly( [ 'type' => 'string' ] ),
				'label'            => $readonly( [ 'type' => 'string' ] ),
				'description'      => $readonly( [ 'type' => 'string' ] ),
				'default_severity' => $readonly(
					[
						'type' => 'string',
						'enum' => [ 'error', 'warning', 'info' ],
					]
				),
				'default_params'   => $readonly( [ 'type' => 'object' ] ),
				'params_schema'    => $readonly( [ 'type' => 'object' ] ),
			],
		];
		return $this->add_additional_fields_schema( $this->schema );
	}
}
