/**
 * Configuration ESLint de @wordpress/scripts, plus les dossiers à ignorer.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'artifacts/**', 'docs/**', 'vendor/**' ],
	},
];
