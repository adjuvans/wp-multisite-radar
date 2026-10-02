<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class SearchHiddenRule implements RuleInterface {

	public function id(): string {
		return 'search_hidden';
	}

	public function label(): string {
		return __( 'Hidden from search engines', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The site asks search engines not to index it (Settings > Reading). Archived, spam and deleted sites are ignored.', 'multisite-radar' );
	}

	public function default_severity(): string {
		return Severity::INFO;
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
	 * is_public reprend la colonne public de wp_blogs, synchronisée par le cœur avec l'option blog_public.
	 * Un site que WordPress ne sert pas n'est de toute façon pas indexé (écart E2 du plan M4).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at || $site->is_public || ! $site->is_served() ) {
			return null;
		}
		return new Alert( $this->id(), $this->default_severity() );
	}

	public function message( array $args ): string {
		return __( 'Search engines are asked not to index this site.', 'multisite-radar' );
	}
}
