<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class HighMediaRule implements RuleInterface {

	public function id(): string {
		return 'high_media';
	}

	public function label(): string {
		return __( 'Many media files', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The media library holds more files than the threshold.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'threshold' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10000000,
					'description' => __( 'Number of media files that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'threshold' => 1000 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		$threshold = (int) $params['threshold'];
		if ( null === $site->scanned_at || $site->media_count < $threshold ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'count'     => $site->media_count,
				'threshold' => $threshold,
			]
		);
	}

	public function message( array $args ): string {
		$count = (int) ( $args['count'] ?? 0 );
		return sprintf(
			/* translators: 1: number of media files, 2: alert threshold. */
			_n( '%1$s media file (threshold: %2$s)', '%1$s media files (threshold: %2$s)', $count, 'multisite-radar' ),
			number_format_i18n( $count ),
			number_format_i18n( (int) ( $args['threshold'] ?? 0 ) )
		);
	}
}
