<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Synthèse des alertes du réseau : sites par gravité maximale et par règle.
 */
final class AlertsQuery {

	private SitesRepository $sites;
	private RuleRegistry $rules;

	public function __construct( SitesRepository $sites, RuleRegistry $rules ) {
		$this->sites = $sites;
		$this->rules = $rules;
	}

	public function summary( int $network_id ): array {
		$rules   = $this->rules->all();
		$counts  = $this->sites->alert_counts( $network_id, array_keys( $rules ) );
		$by_rule = [];
		foreach ( $rules as $id => $rule ) {
			$by_rule[] = [
				'rule'  => $id,
				'label' => $rule->label(),
				'count' => $counts['rules'][ $id ] ?? 0,
			];
		}

		return [
			'total_sites'       => $counts['total'],
			'scanned_sites'     => $counts['total'] - $counts['pending'],
			'pending_sites'     => $counts['pending'],
			'sites_with_alerts' => $counts['with_alerts'],
			'by_severity'       => [
				'error'   => $counts['error'],
				'warning' => $counts['warning'],
				'info'    => $counts['info'],
			],
			'by_rule'           => $by_rule,
		];
	}
}
