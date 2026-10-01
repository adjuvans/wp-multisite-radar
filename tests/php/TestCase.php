<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Plugin;

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
}
