/**
 * Captures de WordPress.org (npm run screenshots), sur l'environnement de test de wp-env :
 * voir tests/e2e/screenshots/wporg.spec.js.
 */
const path = require( 'path' );
const config = require( './playwright.config.js' );

module.exports = {
	...config,
	testDir: path.join( __dirname, 'tests/e2e/screenshots' ),
	retries: 0,
};
