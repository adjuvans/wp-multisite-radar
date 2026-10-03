<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryQuery;
use MultisiteRadar\Query\ScanStatusQuery;
use MultisiteRadar\Query\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/network-summary : vue d'ensemble du réseau, sans paramètre.
 */
final class NetworkSummaryAbility extends Ability {

	private ScanStatusQuery $scan;
	private AlertsQuery $alerts;
	private InventoryQuery $inventory;

	public function __construct( ScanStatusQuery $scan, AlertsQuery $alerts, InventoryQuery $inventory ) {
		$this->scan      = $scan;
		$this->alerts    = $alerts;
		$this->inventory = $inventory;
	}

	public function slug(): string {
		return 'network-summary';
	}

	public function label(): string {
		return __( 'Network summary', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Overview of this WordPress multisite network as last analysed by Multisite Radar: number of sites and analysis progress, sites with alerts by severity and by rule, and counts of installed, unused, missing and outdated plugins and themes. Start here, then use the other Multisite Radar abilities for details.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input( [] );
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'scan'      => Schemas::scan_status(),
				'alerts'    => Schemas::alerts_summary(),
				'inventory' => Schemas::inventory_summary(),
			],
		];
	}

	/**
	 * @param array $input Entrée validée par le cœur.
	 * @return array
	 */
	protected function run( array $input ) {
		return [
			'scan'      => $this->scan->status(),
			'alerts'    => $this->alerts->summary( get_current_network_id() ),
			'inventory' => $this->inventory->summary(),
		];
	}
}
