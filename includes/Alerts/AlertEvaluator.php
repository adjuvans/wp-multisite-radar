<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Applique les règles actives, avec leurs réglages, à un enregistrement de site.
 */
final class AlertEvaluator {

	private RuleRegistry $rules;
	private Settings $settings;
	/** @var array<string, array{enabled: bool, severity: string, params: array}> */
	private array $configs = [];

	public function __construct( RuleRegistry $rules, Settings $settings ) {
		$this->rules    = $rules;
		$this->settings = $settings;
	}

	/**
	 * @return array{enabled: bool, severity: string, params: array}
	 */
	public function config( RuleInterface $rule ): array {
		$id = $rule->id();
		if ( isset( $this->configs[ $id ] ) ) {
			return $this->configs[ $id ];
		}

		$stored = $this->settings->rule_config( $id );
		$params = array_merge( $rule->default_params(), is_array( $stored['params'] ?? null ) ? $stored['params'] : [] );
		if ( true !== rest_validate_value_from_schema( $params, $rule->params_schema(), 'params' ) ) {
			$params = $rule->default_params();
		}
		$severity = isset( $stored['severity'] ) && is_string( $stored['severity'] ) && Severity::is_valid( $stored['severity'] )
			? $stored['severity']
			: $rule->default_severity();

		$this->configs[ $id ] = [
			'enabled'  => (bool) ( $stored['enabled'] ?? true ),
			'severity' => $severity,
			'params'   => $params,
		];
		return $this->configs[ $id ];
	}

	/**
	 * @return Alert[]
	 */
	public function evaluate( SiteRecord $site, int $now ): array {
		$alerts = [];
		foreach ( $this->rules->all() as $rule ) {
			$config = $this->config( $rule );
			if ( ! $config['enabled'] ) {
				continue;
			}
			$alert = $rule->evaluate( $site, $config['params'], $now );
			if ( null === $alert ) {
				continue;
			}
			$alert->severity = $config['severity'];
			$alerts[]        = $alert;
		}
		return $alerts;
	}

	public function apply( SiteRecord $site, int $now ): SiteRecord {
		$alerts = $this->evaluate( $site, $now );
		$level  = 0;
		$ids    = [];
		foreach ( $alerts as $alert ) {
			$level = max( $level, Severity::level( $alert->severity ) );
			$ids[] = $alert->rule;
		}

		$rules = [] === $ids ? '' : ',' . implode( ',', $ids ) . ',';
		if ( strlen( $rules ) > 255 ) {
			$rules = substr( $rules, 0, (int) strrpos( substr( $rules, 0, 255 ), ',' ) + 1 );
		}

		$site->alert_level    = $level;
		$site->alerts_count   = count( $alerts );
		$site->alert_rules    = $rules;
		$site->data['alerts'] = array_map( static fn ( Alert $alert ): array => $alert->to_array(), $alerts );
		return $site;
	}

	public function reset(): void {
		$this->configs = [];
	}
}
