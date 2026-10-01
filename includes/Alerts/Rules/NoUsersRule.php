<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class NoUsersRule implements RuleInterface {

	public function id(): string {
		return 'no_users';
	}

	public function label(): string {
		return __( 'Site without users', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'No user account is attached to the site.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::ERROR;
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
		if ( null === $site->scanned_at || $site->users_count > 0 ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'No user is attached to this site.', 'multisite-radar' );
	}
}
