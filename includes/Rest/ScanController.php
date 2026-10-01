<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Scan\BatchRunner;
use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Storage\SitesRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ScanController extends Controller {

	private const BATCH_BUDGET = 8.0;

	/**
	 * @var string
	 */
	protected $rest_base = 'scan';

	private SitesRepository $sites;
	private BatchRunner $runner;
	private Queue $queue;
	private Lock $lock;

	public function __construct( SitesRepository $sites, BatchRunner $runner, Queue $queue, Lock $lock ) {
		$this->sites  = $sites;
		$this->runner = $runner;
		$this->queue  = $queue;
		$this->lock   = $lock;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/scan',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'request_scan' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'scope' => [
							'type'    => 'string',
							'enum'    => [ 'all', 'dirty', 'ids' ],
							'default' => 'dirty',
						],
						'ids'   => [
							'type'    => 'array',
							'default' => [],
							'items'   => [
								'type'    => 'integer',
								'minimum' => 1,
							],
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/scan/batch',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_batch' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						// Sans ids : les sites marqués du réseau. Avec ids : seulement ceux-là (analyse ciblée de l'interface).
						'ids' => [
							'type'     => 'array',
							'maxItems' => SitesQuery::MAX_INCLUDE,
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/scan/status',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_scan( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$network_id = get_current_network_id();
				$scope      = (string) $request['scope'];
				if ( 'all' === $scope ) {
					$this->queue->request_full_scan( $network_id );
				} elseif ( 'ids' === $scope ) {
					$ids = $this->sites->ids_in_network( (array) $request['ids'], $network_id );
					if ( [] === $ids ) {
						return self::no_sites();
					}
					$this->sites->mark_dirty( $ids );
					$this->queue->continue_soon();
				} else {
					$this->queue->continue_soon();
				}
				return new WP_REST_Response( $this->status() );
			}
		);
	}

	/**
	 * Un lot d'analyse. Avec ids, seuls ces sites sont analysés et remaining ne compte qu'eux : relancer un site
	 * ne fait pas traiter à l'interface l'arriéré de tout le réseau, laissé au cron.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_batch( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$site_ids = null;
				if ( null !== $request['ids'] ) {
					$site_ids = $this->sites->ids_in_network( (array) $request['ids'], get_current_network_id() );
					if ( [] === $site_ids ) {
						return self::no_sites();
					}
				}
				$result = $this->runner->run( min( self::BATCH_BUDGET, BatchRunner::default_budget() ), null, $site_ids );
				$status = $this->status();
				if ( null !== $site_ids ) {
					$status['remaining'] = $result['remaining'];
				}
				return new WP_REST_Response(
					array_merge(
						$status,
						[
							'processed' => $result['processed'],
							'locked'    => $result['locked'],
							'done'      => 0 === $result['remaining'],
						]
					)
				);
			}
		);
	}

	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( $this->status() );
	}

	private static function no_sites(): WP_Error {
		return new WP_Error( 'msradar_no_sites', __( 'None of the requested sites belongs to this network.', 'multisite-radar' ), [ 'status' => 400 ] );
	}

	private function status(): array {
		$network_id = get_current_network_id();
		$last       = (int) get_site_option( Queue::LAST_FULL_SCAN, 0 );
		$next       = Queue::next_run();
		return [
			'total'              => $this->sites->count_all( $network_id ),
			'remaining'          => $this->sites->count_dirty( $network_id ),
			'pending'            => $this->sites->count_pending( $network_id ),
			'locked'             => $this->lock->is_locked(),
			'last_full_scan_gmt' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s', $last ) : null,
			'next_run_gmt'       => null !== $next ? gmdate( 'Y-m-d\TH:i:s', $next ) : null,
		];
	}
}
