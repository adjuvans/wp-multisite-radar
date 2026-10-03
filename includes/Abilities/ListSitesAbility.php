<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/list-sites : les sites du réseau tels que la dernière analyse les a vus, filtrés, triés, paginés.
 */
final class ListSitesAbility extends Ability {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function slug(): string {
		return 'list-sites';
	}

	public function label(): string {
		return __( 'List sites', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the sites of the network as last analysed, with their theme, user, content and media counts, disk, database and autoloaded options sizes in bytes, last activity and highest alert. Filter by text, alert severity, status, theme or plugin; sort and paginate. A null size or date means it was not measured yet; "pending" means the site waits for its first analysis.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'search'      => [
						'type'        => 'string',
						'description' => __( 'Text to find in the site name or address.', 'multisite-radar' ),
					],
					'alert_level' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => Severity::names(),
						],
						'description' => __( 'Only sites whose highest alert has one of these severities ("none": no alert).', 'multisite-radar' ),
					],
					'status'      => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => SitesQuery::STATUSES,
						],
						'description' => __( 'Only sites with one of these statuses.', 'multisite-radar' ),
					],
					'theme'       => [
						'type'        => 'string',
						'description' => __( 'Only sites using this theme folder, as active or parent theme.', 'multisite-radar' ),
					],
					'plugin'      => [
						'type'        => 'string',
						'description' => __( 'Only sites where this plugin file is active, e.g. akismet/akismet.php. A network-activated plugin matches every site.', 'multisite-radar' ),
					],
					'orderby'     => [
						'type'        => 'string',
						'enum'        => array_keys( SitesRepository::ORDERBY ),
						'default'     => 'name',
						'description' => __( 'Sort key.', 'multisite-radar' ),
					],
					'order'       => [
						'type'        => 'string',
						'enum'        => [ 'asc', 'desc' ],
						'default'     => 'asc',
						'description' => __( 'Sort direction.', 'multisite-radar' ),
					],
				],
				self::paging()
			)
		);
	}

	public function output_schema(): array {
		return Schemas::page_of( Schemas::site() );
	}

	/**
	 * @param array $input Entrée validée par le cœur.
	 * @return array
	 */
	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->sites->list(
			[
				'page'        => $page,
				'per_page'    => $per_page,
				'search'      => (string) ( $input['search'] ?? '' ),
				'alert_level' => self::strings( $input['alert_level'] ?? [] ),
				'status'      => self::strings( $input['status'] ?? [] ),
				'theme'       => (string) ( $input['theme'] ?? '' ),
				'plugin'      => (string) ( $input['plugin'] ?? '' ),
				'orderby'     => (string) ( $input['orderby'] ?? 'name' ),
				'order'       => (string) ( $input['order'] ?? 'asc' ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
