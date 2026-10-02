/**
 * Configuration webpack de `@wordpress/scripts`, plus un point d'entrée par vue d'administration : chaque page ne
 * charge que le code de sa vue (écart E2 du plan M2). Le code de node_modules commun aux vues qui affichent
 * DataViews sort dans un chunk partagé, admin/dataviews.js (écart E11 du plan M3). Les blocs de src/blocks/* sont
 * découverts par `@wordpress/scripts` à partir de leur block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const { LicenseWebpackPlugin } = require( 'license-webpack-plugin' );

const VIEWS = [
	'overview',
	'sites',
	'plugins',
	'themes',
	'alerts',
	'settings',
];

// Vues sans DataViews : elles ne dépendent pas du chunk partagé (même liste que Admin\Assets::LIGHT_VIEWS).
const LIGHT_VIEWS = [ 'overview' ];

// Chunk partagé (Admin\Assets::SHARED_CHUNK), enregistré par PHP comme dépendance des autres vues.
const SHARED_CHUNK = 'admin/dataviews';

const SHARED_ENTRIES = VIEWS.filter(
	( view ) => ! LIGHT_VIEWS.includes( view )
).map( ( view ) => `admin/${ view }` );

const { splitChunks } = defaultConfig.optimization;

// La feuille de style de DataViews est importée par les points d'entrée : l'extraction de dépendances la
// prendrait pour un script WordPress (« wp-dataviews/build-style/style.css »), qui n'existe pas. `false` la
// laisse dans le bundle, d'où elle sort dans admin/dataviews.css.
// `instanceof` ne reconnaît que l'instance de `@wordpress/scripts` : le paquet est épinglé en devDependency pour
// qu'une seule copie (hissée) existe. Si aucune instance n'est remplacée, le build s'arrête.
let replaced = 0;
const plugins = defaultConfig.plugins.map( ( plugin ) => {
	if ( ! ( plugin instanceof DependencyExtractionWebpackPlugin ) ) {
		return plugin;
	}
	replaced++;
	return new DependencyExtractionWebpackPlugin( {
		requestToExternal: ( request ) =>
			request.endsWith( '/build-style/style.css' ) ? false : undefined,
	} );
} );
if ( replaced !== 1 ) {
	throw new Error(
		`webpack.config.js: expected one DependencyExtractionWebpackPlugin in the default configuration, found ${ replaced }.`
	);
}

// build/third-party-licenses.txt : chaque paquet embarqué (DataViews et ses dépendances) avec le texte de sa
// licence, comme l'exige la licence MIT. Le pied de page des pages du plugin y renvoie (Admin\Footer).
plugins.push(
	new LicenseWebpackPlugin( {
		perChunkOutput: false,
		outputFilename: 'third-party-licenses.txt',
		addBanner: false,
	} )
);

module.exports = {
	...defaultConfig,
	plugins,
	// Le chunk partagé dépasse la taille conseillée : il est mis en cache par le navigateur.
	performance: { hints: false },
	module: {
		...defaultConfig.module,
		rules: [
			// Le paquet dataviews se déclare sans effet de bord (« sideEffects: false ») : sans cette règle, webpack
			// supprime l'import de sa feuille de style en production.
			{
				test: /@wordpress[\\/]dataviews[\\/]build-style[\\/].*\.css$/,
				sideEffects: true,
			},
			...defaultConfig.module.rules,
		],
	},
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
				// Tout le code de node_modules des vues DataViews, JS et CSS, dans un seul fichier.
				dataviews: {
					test: /[\\/]node_modules[\\/]/,
					name: SHARED_CHUNK,
					chunks: ( chunk ) => SHARED_ENTRIES.includes( chunk.name ),
					enforce: true,
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
