<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class InactiveRule implements RuleInterface {

	public function id(): string {
		return 'inactive';
	}

	public function label(): string {
		return __( 'Inactive site', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'No content of the tracked types has been published or updated for a while.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'months' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 120,
					'title'       => __( 'Months without activity', 'multisite-radar' ),
					'description' => __( 'Months without activity before the alert is raised.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'months' => 6 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || null === $site->last_activity_gmt ) {
			return null;
		}
		$last = strtotime( $site->last_activity_gmt . ' UTC' );
		if ( false === $last ) {
			return null;
		}
		$months = (int) floor( ( $now - $last ) / ( 30 * DAY_IN_SECONDS ) );
		if ( $months < (int) $params['months'] ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity(), [ 'months' => $months ] );
	}

	public function message( array $args ): string {
		$months = (int) ( $args['months'] ?? 0 );
		/* translators: %d: number of months without activity. */
		return sprintf( _n( 'Inactive for %d month', 'Inactive for %d months', $months, 'multisite-radar' ), $months );
	}
}
