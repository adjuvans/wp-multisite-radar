import { addQueryArgs } from '@wordpress/url';

const DEFAULTS = {
	view: 'overview',
	pages: {},
	canManage: false,
	canSeeEmails: false,
	exportUrl: '',
	exportNonce: '',
	preload: {},
	postTypes: [],
	plugins: [],
};

/**
 * Configuration injectée par PHP (Admin\Assets::config()) dans window.msradarAdmin.
 */
export function getConfig() {
	return { ...DEFAULTS, ...( window.msradarAdmin || {} ) };
}

/**
 * URL d'une page de l'application, avec ses paramètres de vue.
 *
 * @param {string} view overview, sites, plugins, themes, users, alerts ou settings.
 * @param {Object} args Paramètres d'URL de la vue.
 */
export function pageUrl( view, args = {} ) {
	const base = getConfig().pages[ view ];
	return base ? addQueryArgs( base, args ) : '';
}
