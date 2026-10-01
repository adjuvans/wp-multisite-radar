<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Plugin;
use MultisiteRadar\Storage\SiteRecord;

/**
 * Base de tous les tests du plugin.
 */
abstract class TestCase extends \WP_UnitTestCase {

	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->reset_caches();
	}

	/**
	 * Enregistre une ligne avec les propriétés données (par défaut : réseau courant, non marquée).
	 */
	protected function make_record( int $site_id, array $props = [] ): SiteRecord {
		$record             = new SiteRecord();
		$record->site_id    = $site_id;
		$record->network_id = get_current_network_id();
		$record->dirty      = false;
		foreach ( $props as $property => $value ) {
			$record->$property = $value;
		}
		$this->plugin()->sites()->save( $record );
		if ( ! $record->dirty ) {
			$this->plugin()->sites()->clear_dirty( $site_id );
		}
		return $record;
	}

	/**
	 * Construit un enregistrement en mémoire (non sauvegardé), déjà analysé.
	 */
	protected function build_record( array $props = [] ): SiteRecord {
		$record             = new SiteRecord();
		$record->scanned_at = '2026-09-01 00:00:00';
		foreach ( $props as $property => $value ) {
			$record->$property = $value;
		}
		return $record;
	}

	protected function mark_all_clean(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty = 0, dirty_since = NULL', Schema::sites_table() ) );
	}

	/**
	 * Exécute un callback comme si la requête visait un autre réseau (réglages, file et curseurs de ce réseau).
	 *
	 * @return mixed Valeur renvoyée par le callback.
	 */
	protected function as_network( int $network_id, callable $callback ) {
		$previous                = $GLOBALS['current_site'];
		$GLOBALS['current_site'] = get_network( $network_id );
		$this->plugin()->reset_caches();
		try {
			return $callback();
		} finally {
			$GLOBALS['current_site'] = $previous;
			$this->plugin()->reset_caches();
		}
	}

	/**
	 * Nombre d'événements cron planifiés pour un hook, toutes échéances confondues.
	 */
	protected function count_cron_events( string $hook ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ $hook ] ) ? count( $hooks[ $hook ] ) : 0;
		}
		return $count;
	}
}
