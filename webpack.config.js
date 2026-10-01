/**
 * Configuration webpack de @wordpress/scripts, plus un point d'entrée par vue d'administration :
 * chaque page ne charge que le code de sa vue (écart E2 du plan M2). Les blocs de src/blocks/* sont
 * découverts par @wordpress/scripts à partir de leur block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const VIEWS = [ 'overview', 'sites', 'alerts', 'settings' ];

const { splitChunks } = defaultConfig.optimization;

module.exports = {
	...defaultConfig,
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...splitChunks,
			cacheGroups: {
				...splitChunks.cacheGroups,
				// Le groupe `style` par défaut regroupe le CSS en un fichier `style-<entrée>` : avec
				// une feuille partagée, il serait nommé d'après la première entrée. Les vues
				// d'administration en sont exclues et émettent chacune leur `admin/<vue>.css`.
				// Les blocs gardent le comportement par défaut (`style-index.css`).
				style: {
					...splitChunks.cacheGroups.style,
					chunks: ( chunk ) =>
						! ( chunk.name || '' ).startsWith( 'admin/' ),
				},
			},
		},
	},
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
