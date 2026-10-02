<?php
namespace MultisiteRadar\Alerts\Rules;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

final class UpdatesPendingRule implements RuleInterface {

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function id(): string {
		return 'updates_pending';
	}

	public function label(): string {
		return __( 'Pending updates', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'A plugin activated on the site, its theme or its parent theme has an update available. Plugins activated on the whole network are not counted. Read from the update checks of WordPress, without any external request.', 'multisite-radar' );
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
	 * Plugins activés sur le site seulement : une mise à jour d'un plugin réseau signalerait chaque site (écart E13).
	 */
	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
		if ( null === $site->scanned_at ) {
			return null;
		}
		$plugin_updates = $this->state->plugin_updates();
		$plugins        = 0;
		foreach ( (array) ( $site->data['plugins_local'] ?? [] ) as $file ) {
			if ( is_string( $file ) && isset( $plugin_updates[ $file ] ) ) {
				++$plugins;
			}
		}
		$theme_updates = $this->state->theme_updates();
		$themes        = 0;
		foreach ( array_unique( array_filter( [ $site->theme_stylesheet, $site->theme_template ] ) ) as $theme ) {
			if ( isset( $theme_updates[ $theme ] ) ) {
				++$themes;
			}
		}
		if ( 0 === $plugins + $themes ) {
			return null;
		}
		return new Alert(
			$this->id(),
			$this->default_severity(),
			[
				'plugins' => $plugins,
				'themes'  => $themes,
			]
		);
	}

	public function message( array $args ): string {
		$parts   = [];
		$plugins = (int) ( $args['plugins'] ?? 0 );
		$themes  = (int) ( $args['themes'] ?? 0 );
		if ( $plugins > 0 ) {
			/* translators: %s: number of plugins. */
			$parts[] = sprintf( _n( '%s plugin', '%s plugins', $plugins, 'multisite-radar' ), number_format_i18n( $plugins ) );
		}
		if ( $themes > 0 ) {
			/* translators: %s: number of themes. */
			$parts[] = sprintf( _n( '%s theme', '%s themes', $themes, 'multisite-radar' ), number_format_i18n( $themes ) );
		}
		if ( 2 === count( $parts ) ) {
			/* translators: 1: number of plugins, such as "2 plugins", 2: number of themes, such as "1 theme". */
			return sprintf( __( 'Updates available for %1$s and %2$s', 'multisite-radar' ), $parts[0], $parts[1] );
		}
		/* translators: %s: number of plugins or themes, such as "2 plugins". */
		return sprintf( __( 'Updates available for %s', 'multisite-radar' ), (string) reset( $parts ) );
	}
}
