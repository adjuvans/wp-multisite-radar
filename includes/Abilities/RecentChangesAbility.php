<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Storage\EventsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/recent-changes : le journal des changements du réseau (spec §5.4), les plus récents d'abord.
 */
final class RecentChangesAbility extends Ability {

	private EventsQuery $events;

	public function __construct( EventsQuery $events ) {
		$this->events = $events;
	}

	public function slug(): string {
		return 'recent-changes';
	}

	public function label(): string {
		return __( 'Recent changes', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the recent changes of the network, newest first: sites created or deleted, plugins activated or deactivated (on a site or on the whole network), themes switched, alerts raised or resolved. Each change has its date (UTC), its site (null for the whole network) and a readable message. Filter by date, kind of change or site.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'since' => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Only changes since this date and time, in UTC, e.g. 2026-09-01T00:00:00.', 'multisite-radar' ),
					],
					'type'  => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => EventsRepository::TYPES,
						],
						'description' => __( 'Only these kinds of changes.', 'multisite-radar' ),
					],
					'site'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Only the changes of this site ID.', 'multisite-radar' ),
					],
				],
				self::paging()
			)
		);
	}

	public function output_schema(): array {
		return Schemas::page_of( Schemas::event() );
	}

	/**
	 * @return array
	 */
	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$since    = isset( $input['since'] ) ? rest_parse_date( (string) $input['since'] ) : false;
		$result   = $this->events->list(
			[
				'page'     => $page,
				'per_page' => $per_page,
				'since'    => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
				'type'     => self::strings( $input['type'] ?? [] ),
				'site'     => (int) ( $input['site'] ?? 0 ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
