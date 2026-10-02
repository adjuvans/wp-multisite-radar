import { __ } from '@wordpress/i18n';

const POST_TYPE_KEY = /^[a-z0-9_-]{1,20}$/;

const SCAN_KEYS = [
	'activity_post_types',
	'analysis_plugins',
	'measure_disk',
	'full_rescan_days',
];

/**
 * Champs DataForm des réglages, adressés par chemin pointé dans l'objet des réglages (msradar_settings).
 *
 * @param {Object} options
 * @param {Array}  options.postTypes Suggestions { value, label } (types publics du site principal).
 * @param {Array}  options.plugins   Plugins installés { value, label }.
 */
export function getSettingsFields( { postTypes = [], plugins = [] } ) {
	return [
		{
			id: 'scan.activity_post_types',
			type: 'array',
			label: __(
				'Content types that count as activity',
				'multisite-radar'
			),
			description: __(
				'Publishing or updating one of these types counts as activity (last activity, inactivity alert). Other keys can be typed in.',
				'multisite-radar'
			),
			elements: postTypes,
			isValid: {
				required: true,
				elements: false,
				custom: ( item ) =>
					( item.scan?.activity_post_types || [] ).every( ( type ) =>
						POST_TYPE_KEY.test( type )
					)
						? null
						: __(
								'Use content type keys: lowercase letters, digits, "-" and "_", 20 characters at most.',
								'multisite-radar'
							),
			},
		},
		{
			id: 'scan.analysis_plugins',
			type: 'array',
			label: __(
				'Only list the content types of these plugins',
				'multisite-radar'
			),
			description: __(
				'Leave empty to list the content types of every plugin.',
				'multisite-radar'
			),
			elements: plugins,
			// Un plugin supprimé depuis l'enregistrement reste stocké : le serveur accepte tout slug.
			isValid: { elements: false },
		},
		{
			id: 'scan.measure_disk',
			type: 'boolean',
			label: __(
				'Measure the disk space used by each site',
				'multisite-radar'
			),
			description: __(
				'Size of the uploads folder of each site. A measure that takes more than two seconds stops there and is shown as a minimum.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
		{
			id: 'scan.full_rescan_days',
			type: 'integer',
			label: __( 'Full analysis every (days)', 'multisite-radar' ),
			description: __(
				'Every site is analysed again at this interval, to catch changes made directly in the database.',
				'multisite-radar'
			),
			isValid: { required: true, min: 1, max: 90 },
		},
		{
			id: 'sites_menu.enabled',
			type: 'boolean',
			label: __(
				'Enable the network sites menu (block, shortcode and menu items)',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
	];
}

export const SCAN_FORM = {
	layout: { type: 'regular' },
	fields: [
		'scan.activity_post_types',
		'scan.analysis_plugins',
		'scan.measure_disk',
		'scan.full_rescan_days',
	],
};

export const MENU_FORM = {
	layout: { type: 'regular' },
	fields: [ 'sites_menu.enabled' ],
};

export const ALL_FORM = {
	layout: { type: 'regular' },
	fields: [ ...SCAN_FORM.fields, ...MENU_FORM.fields ],
};

function isObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

/**
 * Fusion récursive des objets ; une liste est remplacée (comme Settings::merge() en PHP).
 *
 * @param {Object} base  Valeurs de départ.
 * @param {Object} patch Modifications.
 */
export function mergeDeep( base, patch ) {
	const out = { ...base };
	Object.entries( patch || {} ).forEach( ( [ key, value ] ) => {
		out[ key ] =
			isObject( value ) && isObject( base?.[ key ] )
				? mergeDeep( base[ key ], value )
				: value;
	} );
	return out;
}

function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Corps de POST /settings : seules les valeurs différentes des réglages enregistrés (écart E14 du plan M4).
 * Revenir à la valeur de départ n'est donc plus une modification, et deux administrateurs qui changent des réglages
 * différents ne s'écrasent pas.
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function changes( saved, current ) {
	const patch = {};
	SCAN_KEYS.forEach( ( key ) => {
		if ( ! same( saved.scan?.[ key ], current.scan?.[ key ] ) ) {
			patch.scan = { ...patch.scan, [ key ]: current.scan?.[ key ] };
		}
	} );
	if ( !! saved.sites_menu?.enabled !== !! current.sites_menu?.enabled ) {
		patch.sites_menu = { enabled: !! current.sites_menu?.enabled };
	}
	return patch;
}
