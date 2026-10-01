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
}
