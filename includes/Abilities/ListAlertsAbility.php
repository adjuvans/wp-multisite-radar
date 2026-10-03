<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/list-alerts : les alertes du réseau, une entrée par site et par règle.
 */
final class ListAlertsAbility extends Ability {

	private AlertsQuery $alerts;
	private RuleRegistry $rules;

	public function __construct( AlertsQuery $alerts, RuleRegistry $rules ) {
		$this->alerts = $alerts;
		$this->rules  = $rules;
	}

	public function slug(): string {
		return 'list-alerts';
	}

	public function label(): string {
		return __( 'List alerts', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Lists the health alerts of the network, one entry per site and rule, with the severity of the current settings and a readable message: sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines, overdue scheduled tasks, and rules added by other plugins. Rules switched off in the settings are left out.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'severity' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ Severity::ERROR, Severity::WARNING, Severity::INFO ],
						],
						'description' => __( 'Only alerts of these severities.', 'multisite-radar' ),
					],
					'rule'     => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => array_keys( $this->rules->all() ),
						],
						'description' => __( 'Only alerts of these rules.', 'multisite-radar' ),
					],
					'search'   => [
						'type'        => 'string',
						'description' => __( 'Text to find in the site name or address.', 'multisite-radar' ),
					],
					'orderby'  => [
						'type'        => 'string',
						'enum'        => AlertsQuery::ORDERBY,
						'default'     => 'rule',
						'description' => __( 'Sort key.', 'multisite-radar' ),
					],
					'order'    => [
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
		return Schemas::page_of( Schemas::alert() );
	}

	/**
	 * @param array $input Entrée validée par le cœur.
	 * @return array
	 */
	protected function run( array $input ) {
		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->alerts->list(
			[
				'page'     => $page,
				'per_page' => $per_page,
				'search'   => (string) ( $input['search'] ?? '' ),
				'severity' => self::strings( $input['severity'] ?? [] ),
				'rule'     => self::strings( $input['rule'] ?? [] ),
				'orderby'  => (string) ( $input['orderby'] ?? 'rule' ),
				'order'    => (string) ( $input['order'] ?? 'asc' ),
			]
		);
		return self::page( $result, $page, $per_page );
	}
}
