<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins et thèmes : mêmes filtres que GET /plugins et GET /themes. Les listes tiennent en mémoire ; les colonnes
 * sont les clés REST, déjà scalaires.
 */
abstract class InventoryExport implements ExportSource {

	public function filters(): array {
		return [ 'search', 'orderby', 'order', 'status', 'has_update' ];
	}

	public function list_filters(): array {
		return [ 'status' ];
	}

	public function check( array $filters ): void {
		$this->items( [] );
	}

	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int {
		$args               = $filters;
		$args['has_update'] = isset( $filters['has_update'] ) && (bool) rest_sanitize_boolean( (string) $filters['has_update'] );
		$items              = $this->items( $args );
		foreach ( $items as $item ) {
			$row = [];
			foreach ( $keys as $key ) {
				$row[ $key ] = $item[ $key ] ?? null;
			}
			$consumer( $row );
		}
		return count( $items );
	}

	/**
	 * @return array[] Éléments filtrés et triés, sans pagination.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	abstract protected function items( array $args ): array;
}
