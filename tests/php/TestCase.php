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

	protected function mark_all_clean(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty = 0, dirty_since = NULL', Schema::sites_table() ) );
	}
}
