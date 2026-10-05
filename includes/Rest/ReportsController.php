<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\TrendsQuery;
use MultisiteRadar\Reports\Digest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reports/trends : séries du réseau ou d'un site (spec §5.1, écart E9).
 */
final class ReportsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports';

	private TrendsQuery $trends;
	private Digest $digest;

	public function __construct( TrendsQuery $trends, Digest $digest ) {
		$this->trends = $trends;
		$this->digest = $digest;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trends',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_trends' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'days' => [
							'type'    => 'integer',
							'default' => TrendsQuery::DEFAULT_DAYS,
							'minimum' => 2,
							'maximum' => TrendsQuery::MAX_DAYS,
						],
						'site' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_trends_schema' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/digest/test',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send_test_digest' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_trends( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$days = (int) $request['days'];
				if ( isset( $request['site'] ) ) {
					$trends = $this->trends->site( (int) $request['site'], $days );
					if ( null === $trends ) {
						return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
					}
					return new WP_REST_Response( $trends );
				}
				return new WP_REST_Response( $this->trends->network( $days ) );
			}
		);
	}

	public function get_trends_schema(): array {
		return Schemas::for_rest( 'msradar-trends', Schemas::trends() );
	}

	/**
	 * Envoie tout de suite le récapitulatif au seul utilisateur courant (écart E7). La réponse ne contient pas d'adresse.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_digest() {
		return $this->guard(
			function () {
				$email = (string) wp_get_current_user()->user_email;
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'msradar_no_email', __( 'Your account has no valid e-mail address.', 'multisite-radar' ), [ 'status' => 400 ] );
				}
				try {
					$sent = $this->digest->send( [ $email ] );
				} catch ( \RuntimeException $error ) {
					throw $error; // Lecture en échec : la garde répond 500 (msradar_storage_error).
				} catch ( \Throwable $error ) {
					// Un hook tiers (phpmailer_init, pre_wp_mail) a levé une exception : même réponse qu'un envoi raté.
					do_action( 'msradar_error', __METHOD__, $error );
					$sent = false;
				}
				if ( ! $sent ) {
					return new WP_Error( 'msradar_mail_failed', __( 'The e-mail could not be sent. Check the e-mail settings of the server.', 'multisite-radar' ), [ 'status' => 500 ] );
				}
				return new WP_REST_Response( [ 'sent' => true ] );
			}
		);
	}
}
