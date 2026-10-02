<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\SitesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Sites : mêmes filtres que GET /sites, lus par tranches.
 */
final class SitesExport implements ExportSource {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function columns(): array {
		return SitesColumns::all();
	}

	public function filters(): array {
		return [ 'search', 'orderby', 'order', 'alert_level', 'status', 'registry_status', 'rule', 'theme', 'plugin', 'include' ];
	}

	public function list_filters(): array {
		return [ 'alert_level', 'status', 'registry_status', 'include' ];
	}

	public function check( array $filters ): void {
		$this->sites->list( array_merge( $filters, [ 'per_page' => 1 ] ) );
	}

	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int {
		return $this->sites->each(
			$filters,
			static function ( array $item ) use ( $consumer, $keys ): void {
				$consumer( SitesColumns::row( $item, $keys ) );
			},
			$chunk
		);
	}
}
