<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryList;
use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use MultisiteRadar\Query\UsersQuery;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Traduit les paramètres d'URL d'une page d'administration en arguments REST, exactement comme le client
 * (src/views/<vue>/query.js et src/views/inventory/query.js) : le préchargement doit viser la même requête.
 * tests/fixtures/view-queries.json vérifie la parité des deux côtés.
 */
final class ViewQuery {

	public const NAMESPACE = '/multisite-radar/v1';
	public const MAX_PAGE  = 100000;
	private const PER_PAGE = 20;

	public static function sites( array $query, array $prefs ): array {
		$args  = self::base( $query, $prefs['sites']['per_page'] ?? null, array_keys( SitesRepository::ORDERBY ), 'name' );
		$lists = [
			'alert_level'     => Severity::names(),
			'status'          => SitesQuery::STATUSES,
			'registry_status' => SitesQuery::REGISTRY_STATUSES,
		];
		foreach ( $lists as $key => $allowed ) {
			$values = self::subset( $query, $key, $allowed );
			if ( [] !== $values ) {
				$args[ $key ] = implode( ',', $values );
			}
		}
		$rule = self::text( $query, 'rule' );
		if ( 1 === preg_match( RuleRegistry::ID_PATTERN, $rule ) ) {
			$args['rule'] = $rule;
		}
		return $args;
	}

	public static function alerts( array $query, array $prefs ): array {
		$args = self::base( $query, $prefs['alerts']['per_page'] ?? null, AlertsQuery::ORDERBY, 'rule' );
		// Du plus grave au moins grave, sans « none » (jamais une alerte).
		$severities = array_reverse( array_values( array_diff( Severity::names(), [ Severity::NONE ] ) ) );
		$severity   = self::subset( $query, 'severity', $severities );
		if ( [] !== $severity ) {
			$args['severity'] = implode( ',', $severity );
		}
		$rules = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', explode( ',', self::text( $query, 'rule' ) ) ),
					static fn ( string $rule ): bool => 1 === preg_match( RuleRegistry::ID_PATTERN, $rule )
				)
			)
		);
		sort( $rules, SORT_STRING );
		if ( [] !== $rules ) {
			$args['rule'] = implode( ',', $rules );
		}
		return $args;
	}

	public static function plugins( array $query, array $prefs ): array {
		return self::inventory( $query, $prefs['plugins']['per_page'] ?? null, PluginsQuery::STATUSES );
	}

	public static function themes( array $query, array $prefs ): array {
		return self::inventory( $query, $prefs['themes']['per_page'] ?? null, ThemesQuery::STATUSES );
	}

	public static function users( array $query, array $prefs ): array {
		$args       = self::base( $query, $prefs['users']['per_page'] ?? null, UsersQuery::ORDERBY, 'login' );
		$membership = self::text( $query, 'membership' );
		if ( in_array( $membership, UsersQuery::MEMBERSHIPS, true ) ) {
			$args['membership'] = $membership;
		}
		if ( '1' === self::text( $query, 'super_admin' ) ) {
			$args['super_admin'] = 1;
		}
		return $args;
	}

	/**
	 * Plugins et thèmes : arguments communs, statuts dans l'ordre canonique, mises à jour seulement.
	 *
	 * @param mixed    $per_page Préférence enregistrée.
	 * @param string[] $statuses Statuts autorisés.
	 */
	private static function inventory( array $query, $per_page, array $statuses ): array {
		$args   = self::base( $query, $per_page, InventoryList::ORDERBY, 'name' );
		$status = self::subset( $query, 'status', $statuses );
		if ( [] !== $status ) {
			$args['status'] = implode( ',', $status );
		}
		if ( '1' === self::text( $query, 'has_update' ) ) {
			$args['has_update'] = 1;
		}
		return $args;
	}

	public static function site_id( array $query ): int {
		$value = self::text( $query, 'site' );
		return 1 === preg_match( '/^\d+\z/', $value ) && (int) $value > 0 ? (int) $value : 0;
	}

	/**
	 * Chemin REST avec clés triées, valeurs vides omises et valeurs encodées par rawurlencode (le client normalise les deux écritures).
	 */
	public static function path( string $route, array $args = [] ): string {
		ksort( $args, SORT_STRING );
		$pairs = [];
		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return self::NAMESPACE . $route . ( [] === $pairs ? '' : '?' . implode( '&', $pairs ) );
	}

	/**
	 * Arguments communs aux deux listes, dans l'ordre : page, per_page, orderby, order, search.
	 *
	 * @param mixed    $per_page Préférence enregistrée.
	 * @param string[] $orderbys Tris autorisés.
	 */
	private static function base( array $query, $per_page, array $orderbys, string $default_orderby ): array {
		$orderby = self::text( $query, 'orderby' );
		$args    = [
			'page'     => self::page( $query ),
			'per_page' => in_array( $per_page, Preferences::PER_PAGE, true ) ? (int) $per_page : self::PER_PAGE,
			'orderby'  => in_array( $orderby, $orderbys, true ) ? $orderby : $default_orderby,
			'order'    => 'desc' === self::text( $query, 'order' ) ? 'desc' : 'asc',
		];
		$search  = trim( self::text( $query, 's' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		return $args;
	}

	private static function text( array $query, string $key ): string {
		$value = $query[ $key ] ?? '';
		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}

	/**
	 * Chiffres seulement (« 1e3 » n'est pas une page), bornés à MAX_PAGE.
	 */
	private static function page( array $query ): int {
		$value = self::text( $query, 'paged' );
		if ( 1 !== preg_match( '/^\d+\z/', $value ) ) {
			return 1;
		}
		return max( 1, min( self::MAX_PAGE, (int) $value ) );
	}

	/**
	 * Valeurs autorisées présentes dans une liste séparée par des virgules, dans l'ordre de $allowed.
	 *
	 * @param string[] $allowed
	 * @return string[]
	 */
	private static function subset( array $query, string $key, array $allowed ): array {
		$given = array_map( 'trim', explode( ',', self::text( $query, $key ) ) );
		return array_values( array_intersect( $allowed, $given ) );
	}
}
