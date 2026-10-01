/**
 * Configuration ESLint de @wordpress/scripts, plus les dossiers à ignorer.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	...[]
		.concat(
			require( '@wordpress/eslint-plugin' ).configs[
				'test-playwright'
			] || []
		)
		.map( ( config ) => ( {
			...config,
			files: [ 'tests/e2e/**/*.js' ],
		} ) ),
	{
		ignores: [ 'artifacts/**', 'docs/**', 'vendor/**' ],
	},
];
