<?php
namespace MultisiteRadar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Exécute du code dans le contexte du site principal (où vivent nos événements cron).
 */
final class MainSite {

	/**
	 * @return mixed Valeur renvoyée par le callback.
	 */
	public static function run( callable $callback ) {
		$main   = get_main_site_id();
		$switch = get_current_blog_id() !== $main;
		if ( $switch ) {
			switch_to_blog( $main );
		}
		try {
			return $callback();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Planifie un événement unique immédiat sur le site principal, s'il n'y en a pas déjà un.
	 */
	public static function schedule_once( string $hook ): void {
		self::run(
			static function () use ( $hook ): void {
				if ( false === wp_next_scheduled( $hook ) ) {
					wp_schedule_single_event( time(), $hook );
				}
			}
		);
	}
}
