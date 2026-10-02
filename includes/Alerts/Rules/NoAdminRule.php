<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class NoAdminRule implements RuleInterface {

	public function id(): string {
		return 'no_admin';
	}

	public function label(): string {
		return __( 'Site without administrator', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The site has accounts, but none of them has the administrator role. Super admins are not counted.', 'multisite-radar' );
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

	/**
	 * Un site sans aucun compte relève de no_users (écart E3 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || 0 === $site->users_count || $site->admins_count > 0 ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'No account has the administrator role on this site.', 'multisite-radar' );
	}
}
