/**
 * Tests E2E sur le multisite wp-env (.wp-env.json), avec la configuration Playwright de @wordpress/scripts.
 * L'environnement est démarré à part (npm run wp-env -- start, puis npm run e2e:setup).
 *
 * Le port par défaut est 8888. Pour un autre port, exporter WP_ENV_PORT (et WP_ENV_TESTS_PORT)
 * pour wp-env, et WP_BASE_URL (ex. http://localhost:8890) pour Playwright et tests/e2e/setup.sh.
 */
const path = require( 'path' );

process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: path.join( __dirname, 'tests/e2e/specs' ),
	webServer: undefined,
};
