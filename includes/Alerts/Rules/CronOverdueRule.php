<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class CronOverdueRule implements RuleInterface {

	public function id(): string {
		return 'cron_overdue';
	}

	public function label(): string {
		return __( 'Overdue scheduled tasks', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'At the last analysis, scheduled tasks (WP-Cron) of the site had been waiting past their due time. Archived, spam and deleted sites are ignored: their tasks cannot run.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'hours' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 720,
					'title'       => __( 'Delay (hours)', 'multisite-radar' ),
					'description' => __( 'Hours the oldest overdue task had been waiting, at the last analysis, before the alert is raised.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'hours' => 24 ];
	}

	/**
	 * Le retard est mesuré à la date de l'analyse, pas à $now : le recalcul quotidien part des données stockées, et la
	 * tâche a pu s'exécuter depuis (écart E1 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || ! $site->is_served() ) {
			return null;
		}
		$cron    = is_array( $site->data['cron'] ?? null ) ? $site->data['cron'] : [];
		$oldest  = is_string( $cron['oldest_overdue_gmt'] ?? null ) ? strtotime( $cron['oldest_overdue_gmt'] . ' UTC' ) : false;
		$scanned = strtotime( $site->scanned_at . ' UTC' );
		if ( false === $oldest || false === $scanned ) {
			return null;
		}
		$hours = (int) floor( ( $scanned - $oldest ) / HOUR_IN_SECONDS );
		if ( $hours < (int) $params['hours'] ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'count' => max( 1, (int) ( $cron['overdue_count'] ?? 1 ) ),
				'hours' => $hours,
			]
		);
	}

	public function message( array $args ): string {
		$count = (int) ( $args['count'] ?? 0 );
		return sprintf(
			/* translators: 1: number of overdue scheduled tasks, 2: how long the oldest one had been waiting, such as "2 days". */
			_n(
				'At the last analysis, %1$s scheduled task was overdue; the oldest had been waiting for %2$s.',
				'At the last analysis, %1$s scheduled tasks were overdue; the oldest had been waiting for %2$s.',
				$count,
				'multisite-radar'
			),
			number_format_i18n( $count ),
			human_time_diff( 0, (int) ( $args['hours'] ?? 0 ) * HOUR_IN_SECONDS )
		);
	}
}
