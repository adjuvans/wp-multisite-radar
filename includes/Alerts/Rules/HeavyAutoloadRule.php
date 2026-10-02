<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class HeavyAutoloadRule implements RuleInterface {

	public function id(): string {
		return 'heavy_autoload';
	}

	public function label(): string {
		return __( 'Heavy autoloaded options', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The options that WordPress loads on every page of the site weigh more than the threshold, which slows the whole site down.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'kilobytes' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1048576,
					'title'       => __( 'Threshold (KB)', 'multisite-radar' ),
					'description' => __( 'Size of the autoloaded options, in kilobytes, that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'kilobytes' => 800 ];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		$threshold = (int) $params['kilobytes'];
		if ( null === $site->scanned_at || null === $site->autoload_bytes || $site->autoload_bytes < $threshold * KB_IN_BYTES ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'kilobytes' => (int) floor( $site->autoload_bytes / KB_IN_BYTES ),
				'threshold' => $threshold,
			]
		);
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: 1: size of the autoloaded options in kilobytes, 2: alert threshold in kilobytes. */
			__( '%1$s KB of autoloaded options (threshold: %2$s KB)', 'multisite-radar' ),
			number_format_i18n( (int) ( $args['kilobytes'] ?? 0 ) ),
			number_format_i18n( (int) ( $args['threshold'] ?? 0 ) )
		);
	}
}
