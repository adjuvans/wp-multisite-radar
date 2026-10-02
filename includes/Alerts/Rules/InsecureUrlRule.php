<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class InsecureUrlRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'insecure_url';
	}

	public function label(): string {
		return __( 'Address in http on an https network', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The address of the site, or its WordPress address, starts with http:// while the main site of the network uses https://.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::WARNING;
	}

	public function params_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function default_params(): array {
		return [];
	}

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || ! $this->state->uses_https() ) {
			return null;
		}
		foreach ( [ $site->url, $site->siteurl ] as $url ) {
			if ( 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
				return new Alert( $this->id(), $this->default_severity(), [ 'url' => $url ] );
			}
		}
		return null;
	}

	public function message( array $args ): string {
		return sprintf(
			/* translators: %s: address of the site. */
			__( 'The address %s uses http while the network uses https.', 'multisite-radar' ),
			(string) ( $args['url'] ?? '' )
		);
	}
}
