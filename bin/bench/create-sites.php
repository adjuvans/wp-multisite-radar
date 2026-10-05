<?php
/**
 * Banc de performance, appelé par bin/bench.sh (wp eval-file) : crée BENCH_SITES sites /bench-1/, /bench-2/…
 * avec le contenu par défaut de WordPress et le compte admin comme administrateur.
 *
 * @package MultisiteRadar
 */

( static function (): void {
	$count   = max( 1, (int) getenv( 'BENCH_SITES' ) );
	$network = get_network();
	for ( $i = 1; $i <= $count; $i++ ) {
		$site = wp_insert_site(
			[
				'domain'  => $network->domain,
				'path'    => $network->path . 'bench-' . $i . '/',
				'title'   => 'Bench site ' . $i,
				'user_id' => 1,
			]
		);
		if ( is_wp_error( $site ) ) {
			WP_CLI::error( $site->get_error_message() );
		}
		if ( 0 === $i % 250 ) {
			WP_CLI::log( sprintf( '%d sites created.', $i ) );
			// Le cache objet de WP-CLI grossit à chaque site : on le vide de temps en temps.
			\WP_CLI\Utils\wp_clear_object_cache();
		}
	}
} )();
