<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\SitesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Lit toutes les pages d'une liste paginée des services Query (au plus 100 éléments par page).
 */
final class Pages {

	public const PER_PAGE = 100;

	/**
	 * S'arrête au total annoncé, sur une page vide (des éléments ont disparu entre deux lectures) ou à la dernière page
	 * que les services acceptent.
	 *
	 * @param callable $fetch Reçoit ( int $page, int $per_page ), renvoie array{items: array[], total: int}.
	 * @return array[] Tous les éléments, dans l'ordre des pages.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public static function collect( callable $fetch ): array {
		$items = [];
		$page  = 1;
		do {
			$result = $fetch( $page, self::PER_PAGE );
			foreach ( $result['items'] as $item ) {
				$items[] = $item;
			}
			++$page;
			$fetched = count( $items );
		} while ( [] !== $result['items'] && $fetched < $result['total'] && $page <= SitesQuery::MAX_PAGE );
		return $items;
	}
}
