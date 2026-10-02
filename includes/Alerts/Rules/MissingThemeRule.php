<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class MissingThemeRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'missing_theme';
	}

	public function label(): string {
		return __( 'Missing active theme', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'The active theme of the site, or its parent theme, is not installed on the network.', 'multisite-radar' );
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
		if ( null === $site->scanned_at || '' === $site->theme_stylesheet ) {
			return null;
		}
		$installed = $this->state->installed_themes();
		if ( ! isset( $installed[ $site->theme_stylesheet ] ) ) {
			return $this->alert( $site->theme_stylesheet, 'theme' );
		}
		$template = $site->theme_template;
		if ( '' !== $template && $template !== $site->theme_stylesheet && ! isset( $installed[ $template ] ) ) {
			return $this->alert( $template, 'parent' );
		}
		return null;
	}

	private function alert( string $theme, string $missing ): Alert {
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'theme'   => $theme,
				'missing' => $missing,
			]
		);
	}

	public function message( array $args ): string {
		$theme = (string) ( $args['theme'] ?? '' );
		if ( 'parent' === ( $args['missing'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: folder of the missing parent theme. */
				__( 'The parent theme "%s" of the active theme is not installed.', 'multisite-radar' ),
				$theme
			);
		}
		return sprintf(
			/* translators: %s: folder of the missing theme. */
			__( 'The active theme "%s" is not installed.', 'multisite-radar' ),
			$theme
		);
	}
}
