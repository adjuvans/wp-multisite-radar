<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class DiskQuotaRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'disk_quota';
	}

	public function label(): string {
		return __( 'Upload quota almost reached', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The uploads of the site fill most of their space quota. Only when upload quotas are enabled in the network settings and the disk usage is measured.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'percent' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'title'       => __( 'Share of the quota', 'multisite-radar' ),
					'description' => __( 'Percentage of the upload quota that raises the alert.', 'multisite-radar' ),
				],
			],
		];
	}

	public function default_params(): array {
		return [ 'percent' => 90 ];
	}

	/**
	 * Quota propre au site s'il en a un, sinon celui du réseau (écart E11 du plan M4). Une mesure arrêtée par son
	 * budget est un minimum : si elle dépasse déjà le seuil, l'alerte est juste.
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || null === $site->disk_bytes || ! $this->state->quotas_enabled() ) {
			return null;
		}
		$own   = $site->data['options']['upload_space_mb'] ?? null;
		$quota = is_int( $own ) ? $own : $this->state->default_quota_mb();
		if ( $quota <= 0 || $site->disk_bytes * 100 < $quota * MB_IN_BYTES * (int) $params['percent'] ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'used_mb'  => (int) round( $site->disk_bytes / MB_IN_BYTES ),
				'quota_mb' => $quota,
			]
		);
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: 1: disk space used in megabytes, 2: upload quota in megabytes. */
			__( '%1$s MB used of the %2$s MB upload quota', 'multisite-radar' ),
			number_format_i18n( (int) ( $args['used_mb'] ?? 0 ) ),
			number_format_i18n( (int) ( $args['quota_mb'] ?? 0 ) )
		);
	}
}
