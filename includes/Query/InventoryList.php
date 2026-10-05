<?php
namespace MultisiteRadar\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Listes d'inventaire (plugins, thèmes) construites en mémoire : quelques centaines d'éléments au plus.
 * Recherche sans casse, tri par nom, nombre de sites, état, version ou mise à jour avec départage stable, pagination bornée comme en REST.
 * Chaque élément a au moins id (string), name (string) et sites_count (int).
 */
final class InventoryList {

	public const ORDERBY = [ 'name', 'sites_count', 'status', 'version', 'update_version' ];

	/**
	 * Le texte cherché apparaît-il dans l'une des valeurs, sans tenir compte de la casse ?
	 */
	public static function matches( string $search, string ...$values ): bool {
		if ( '' === $search ) {
			return true;
		}
		foreach ( $values as $value ) {
			$found = function_exists( 'mb_stripos' ) ? mb_stripos( $value, $search, 0, 'UTF-8' ) : stripos( $value, $search );
			if ( false !== $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tri par nom (ordre naturel, sans casse), nombre de sites, état (dans l'ordre de $statuses ; un état inconnu en
	 * dernier), version (version_compare) ou mise à jour (sans mise à jour d'abord) ; à égalité, par nom puis par
	 * identifiant, quel que soit le sens.
	 *
	 * @param array[]  $items
	 * @param string[] $statuses États dans l'ordre du tri croissant.
	 * @return array[]
	 */
	public static function sort( array $items, string $orderby, string $order, array $statuses = [] ): array {
		$sign = 'desc' === strtolower( $order ) ? -1 : 1;
		$rank = array_flip( array_values( $statuses ) );
		usort(
			$items,
			static function ( array $a, array $b ) use ( $orderby, $sign, $rank ): int {
				$primary = self::compare( $a, $b, $orderby, $rank );
				if ( 0 !== $primary ) {
					return $sign * $primary;
				}
				$name = strnatcasecmp( $a['name'], $b['name'] );
				return 0 !== $name ? $name : strcmp( $a['id'], $b['id'] );
			}
		);
		return $items;
	}

	/**
	 * @param array<string, int> $rank État => rang.
	 */
	private static function compare( array $a, array $b, string $orderby, array $rank ): int {
		switch ( $orderby ) {
			case 'sites_count':
				return $a['sites_count'] <=> $b['sites_count'];
			case 'status':
				return ( $rank[ (string) ( $a['status'] ?? '' ) ] ?? PHP_INT_MAX ) <=> ( $rank[ (string) ( $b['status'] ?? '' ) ] ?? PHP_INT_MAX );
			case 'version':
				return version_compare( (string) ( $a['version'] ?? '' ), (string) ( $b['version'] ?? '' ) );
			case 'update_version':
				return ( null !== ( $a['update_version'] ?? null ) ) <=> ( null !== ( $b['update_version'] ?? null ) );
			default:
				return strnatcasecmp( $a['name'], $b['name'] );
		}
	}

	/**
	 * Une page, avec les bornes des routes REST (100 par page au plus, page ≤ SitesQuery::MAX_PAGE).
	 *
	 * @param array[] $items
	 * @return array{items: array[], total: int}
	 */
	public static function slice( array $items, int $page, int $per_page ): array {
		$per_page = min( 100, max( 1, $per_page ) );
		$page     = min( SitesQuery::MAX_PAGE, max( 1, $page ) );
		return [
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $items ),
		];
	}

	/**
	 * Versions proposées par la dernière vérification de WordPress, lues dans un transient réseau : aucun appel externe.
	 *
	 * @param string $transient update_plugins ou update_themes.
	 * @return array<string, string> Fichier du plugin ou dossier du thème => nouvelle version.
	 */
	public static function updates( string $transient ): array {
		$value    = get_site_transient( $transient );
		$data     = is_object( $value ) ? get_object_vars( $value ) : [];
		$response = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : [];
		$updates  = [];
		foreach ( $response as $key => $update ) {
			$fields  = is_object( $update ) ? get_object_vars( $update ) : ( is_array( $update ) ? $update : [] );
			$version = $fields['new_version'] ?? '';
			if ( is_scalar( $version ) && '' !== (string) $version ) {
				$updates[ (string) $key ] = (string) $version;
			}
		}
		return $updates;
	}
}
