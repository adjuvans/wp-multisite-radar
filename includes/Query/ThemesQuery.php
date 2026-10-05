<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des thèmes du réseau courant : présents sur le disque, ou encore utilisés par un site alors qu'ils ont
 * disparu. Un thème est utilisé s'il est actif sur un site ou parent du thème actif d'un site (spec §7.1).
 */
final class ThemesQuery {

	public const STATUS_USED    = 'used';
	public const STATUS_UNUSED  = 'unused';
	public const STATUS_MISSING = 'missing';
	public const STATUSES       = [ self::STATUS_USED, self::STATUS_UNUSED, self::STATUS_MISSING ];

	private SitesRepository $sites;

	public function __construct( SitesRepository $sites ) {
		$this->sites = $sites;
	}

	public static function defaults(): array {
		return [
			'page'       => 1,
			'per_page'   => 20,
			'search'     => '',
			'status'     => [],
			'has_update' => false,
			'orderby'    => 'name',
			'order'      => 'asc',
		];
	}

	/**
	 * Thèmes présents sur le disque, y compris ceux que WordPress juge abîmés (parent ou modèle manquant) :
	 * un thème présent n'est jamais annoncé « introuvable ».
	 *
	 * @return array<string, array{name: string, version: string, template: string}> Dossier => thème.
	 */
	public static function installed(): array {
		$themes = [];
		foreach ( wp_get_themes( [ 'errors' => null ] ) as $stylesheet => $theme ) {
			$themes[ (string) $stylesheet ] = [
				'name'     => PlainText::from_html( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ),
				'version'  => (string) $theme->get( 'Version' ),
				'template' => (string) $theme->get_template(),
			];
		}
		return $themes;
	}

	/**
	 * Tous les thèmes du réseau, triés par dossier.
	 *
	 * @return array<string, array> Dossier => thème (forme REST).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function all(): array {
		$installed = self::installed();
		$counts    = $this->sites->theme_counts( get_current_network_id() );
		// Lu directement : WP_Theme::get_allowed_on_network() garde la première valeur lue dans une variable statique.
		$allowed = array_map( 'strval', array_keys( array_filter( (array) get_site_option( 'allowedthemes', [] ) ) ) );
		$updates = InventoryList::updates( 'update_themes' );

		$slugs = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $installed ), array_keys( $counts['active'] ), array_keys( $counts['parent'] ) ) ) ) );
		sort( $slugs, SORT_STRING );

		$items = [];
		foreach ( $slugs as $stylesheet ) {
			$theme  = $installed[ $stylesheet ] ?? null;
			$active = $counts['active'][ $stylesheet ] ?? 0;
			$parent = $counts['parent'][ $stylesheet ] ?? 0;
			if ( null === $theme ) {
				$status = self::STATUS_MISSING;
			} elseif ( $active + $parent > 0 ) {
				$status = self::STATUS_USED;
			} else {
				$status = self::STATUS_UNUSED;
			}
			$items[ $stylesheet ] = [
				'id'                 => $stylesheet,
				'stylesheet'         => $stylesheet,
				'name'               => null !== $theme && '' !== $theme['name'] ? $theme['name'] : $stylesheet,
				'version'            => null !== $theme ? $theme['version'] : '',
				'installed'          => null !== $theme,
				'parent'             => null !== $theme && '' !== $theme['template'] && $theme['template'] !== $stylesheet ? $theme['template'] : null,
				'allowed_on_network' => in_array( $stylesheet, $allowed, true ),
				'active_count'       => $active,
				'parent_count'       => $parent,
				'sites_count'        => $active + $parent,
				'status'             => $status,
				'update_version'     => null !== $theme ? ( $updates[ $stylesheet ] ?? null ) : null,
			];
		}
		return $items;
	}

	/**
	 * Thèmes qui correspondent aux filtres, triés, sans pagination (exports).
	 *
	 * @return array[]
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function filtered( array $args ): array {
		$args     = array_merge( self::defaults(), $args );
		$search   = trim( (string) $args['search'] );
		$statuses = array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) );
		$updates  = (bool) $args['has_update'];
		$items    = array_filter(
			$this->all(),
			static function ( array $item ) use ( $search, $statuses, $updates ): bool {
				return InventoryList::matches( $search, $item['name'], $item['stylesheet'] )
					&& ( [] === $statuses || in_array( $item['status'], $statuses, true ) )
					&& ( ! $updates || null !== $item['update_version'] );
			}
		);
		return InventoryList::sort( array_values( $items ), (string) $args['orderby'], (string) $args['order'], self::STATUSES );
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args = array_merge( self::defaults(), $args );
		return InventoryList::slice( $this->filtered( $args ), (int) $args['page'], (int) $args['per_page'] );
	}

	/**
	 * @return array|null Null si le thème n'est ni présent ni utilisé par un site du réseau.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( string $stylesheet ): ?array {
		return $this->all()[ $stylesheet ] ?? null;
	}

	/**
	 * @return array{installed: int, unused: int, missing: int, updates: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary(): array {
		$summary = [
			'installed' => 0,
			'unused'    => 0,
			'missing'   => 0,
			'updates'   => 0,
		];
		foreach ( $this->all() as $item ) {
			$summary['installed'] += $item['installed'] ? 1 : 0;
			$summary['unused']    += self::STATUS_UNUSED === $item['status'] ? 1 : 0;
			$summary['missing']   += self::STATUS_MISSING === $item['status'] ? 1 : 0;
			$summary['updates']   += null !== $item['update_version'] ? 1 : 0;
		}
		return $summary;
	}
}
