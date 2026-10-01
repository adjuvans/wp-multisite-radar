/**
 * Configuration webpack de @wordpress/scripts, plus un point d'entrée par vue d'administration :
 * chaque page ne charge que le code de sa vue (écart E2 du plan M2). Les blocs de src/blocks/* sont
 * découverts par @wordpress/scripts à partir de leur block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const VIEWS = [ 'overview', 'sites', 'alerts', 'settings' ];

module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		...Object.fromEntries(
			VIEWS.map( ( view ) => [
				`admin/${ view }`,
				path.resolve( __dirname, `src/admin/${ view }.js` ),
			] )
		),
	} ),
};
