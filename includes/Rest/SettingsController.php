<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings';

	private Settings $settings;
	private RuleRegistry $rules;

	public function __construct( Settings $settings, RuleRegistry $rules ) {
		$this->settings = $settings;
		$this->rules    = $rules;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_item_schema(): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'msradar-settings',
			],
			Settings::schema()
		);
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$patch = (array) $request->get_json_params();
		if ( [] === $patch ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$rules = $patch['alerts']['rules'] ?? [];
		foreach ( is_array( $rules ) ? $rules : [] as $rule_id => $config ) {
			$rule = $this->rules->get( (string) $rule_id );
			if ( null === $rule ) {
				return new WP_Error(
					'msradar_unknown_rule',
					/* translators: %s: alert rule identifier. */
					sprintf( __( 'Unknown alert rule: %s', 'multisite-radar' ), (string) $rule_id ),
					[ 'status' => 400 ]
				);
			}
			if ( is_array( $config ) && array_key_exists( 'params', $config ) ) {
				$valid = rest_validate_value_from_schema( $config['params'], $rule->params_schema(), 'params' );
				if ( is_wp_error( $valid ) ) {
					return new WP_Error( 'msradar_invalid_settings', $valid->get_error_message(), [ 'status' => 400 ] );
				}
			}
		}

		$result = $this->settings->update( $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
}
