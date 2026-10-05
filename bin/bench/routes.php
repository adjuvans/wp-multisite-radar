<?php
/**
 * Banc de performance, appelé par bin/bench.sh (wp --user=admin eval-file). Mesure les routes de lecture de
 * l'interface dans le processus, cache objet vidé avant chaque appel comme pour une requête neuve sans cache
 * persistant (écart E1 du plan M7), puis un lot d'analyse de l'interface (POST /scan/batch).
 * Écrit des lignes Markdown ; code de sortie 1 si une variante de GET /sites dépasse BENCH_MAX_MS au p95, si le lot
 * dépasse 20 secondes ou si une route échoue.
 *
 * @package MultisiteRadar
 */

( static function (): void {
	$runs   = max( 1, (int) getenv( 'BENCH_RUNS' ) );
	$max_ms = (float) getenv( 'BENCH_MAX_MS' );
	$theme  = (string) get_option( 'stylesheet' );
	$routes = [
		[ 'GET /sites', '/sites', [] ],
		[ 'GET /sites?search=Bench site 42', '/sites', [ 'search' => 'Bench site 42' ] ],
		[
			'GET /sites?orderby=content_count&order=desc',
			'/sites',
			[
				'orderby' => 'content_count',
				'order'   => 'desc',
			],
		],
		[
			'GET /sites?theme=' . $theme . '&page=10',
			'/sites',
			[
				'theme' => $theme,
				'page'  => 10,
			],
		],
		[ 'GET /alerts/summary', '/alerts/summary', [] ],
		[ 'GET /inventory/summary', '/inventory/summary', [] ],
		[ 'GET /scan/status', '/scan/status', [] ],
	];

	$failed = false;
	WP_CLI::log( '| Route | Median (ms) | p95 (ms) | Max (ms) |' );
	WP_CLI::log( '|---|---:|---:|---:|' );
	foreach ( $routes as $route ) {
		$times = [];
		// Le premier appel, qui charge les classes, n'est pas compté.
		for ( $run = 0; $run <= $runs; $run++ ) {
			wp_cache_flush();
			$request = new WP_REST_Request( 'GET', '/multisite-radar/v1' . $route[1] );
			$request->set_query_params( $route[2] );
			$start    = microtime( true );
			$response = rest_do_request( $request );
			$elapsed  = ( microtime( true ) - $start ) * 1000;
			if ( 200 !== $response->get_status() ) {
				WP_CLI::error( sprintf( '%s answered HTTP %d.', $route[0], $response->get_status() ) );
			}
			if ( $run > 0 ) {
				$times[] = $elapsed;
			}
		}
		sort( $times );
		$count  = count( $times );
		$median = $times[ intdiv( $count - 1, 2 ) ];
		$p95    = $times[ (int) ceil( 0.95 * $count ) - 1 ];
		WP_CLI::log( sprintf( '| %s | %.1f | %.1f | %.1f |', $route[0], $median, $p95, $times[ $count - 1 ] ) );
		if ( 0 === strpos( $route[0], 'GET /sites' ) && $p95 > $max_ms ) {
			$failed = true;
		}
	}

	\MultisiteRadar\Plugin::instance()->queue()->request_full_scan( get_current_network_id() );
	$start    = microtime( true );
	$response = rest_do_request( new WP_REST_Request( 'POST', '/multisite-radar/v1/scan/batch' ) );
	$seconds  = microtime( true ) - $start;
	$data     = (array) $response->get_data();
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '- One interface batch (POST /scan/batch): %d site(s) in %.1f s (limit: 20 s).', (int) ( $data['processed'] ?? 0 ), $seconds ) );
	if ( 200 !== $response->get_status() || $seconds > 20 ) {
		$failed = true;
	}

	if ( $failed ) {
		WP_CLI::halt( 1 );
	}
} )();
