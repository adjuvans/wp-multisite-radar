import { __ } from '@wordpress/i18n';
import { ruleConfig, ruleForm } from './rule-fields';

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
		{
			id: 'integrations.mcp_public',
			type: 'boolean',
			label: __(
				'Let AI assistants read the audit through MCP',
				'multisite-radar'
			),
			description: __(
				'Offers the read-only abilities of Multisite Radar (network summary, sites, plugin and theme usage, alerts, recent changes) to AI assistants connected with the MCP Adapter plugin. They act as the connected user, who needs the same rights as for these pages.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
		{
			id: 'retention.events_days',
			type: 'integer',
			label: __( 'Keep the changes for (days)', 'multisite-radar' ),
			description: __(
				'Older changes are deleted every day.',
				'multisite-radar'
			),
			isValid: { required: true, min: 1, max: 3650 },
		},
		{
			id: 'retention.snapshots_days',
			type: 'integer',
			label: __( 'Keep the daily figures for (days)', 'multisite-radar' ),
			description: __(
				'Older daily figures, used by the trends, are deleted every day.',
				'multisite-radar'
			),
			isValid: { required: true, min: 1, max: 3650 },
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

export const INTEGRATIONS_FORM = {
	layout: { type: 'regular' },
	fields: [ 'integrations.mcp_public' ],
};

export const RETENTION_FORM = {
	layout: { type: 'regular' },
	fields: [ 'retention.events_days', 'retention.snapshots_days' ],
};

/**
 * Formulaire complet, pour la validation : analyse, menu des sites, puis les champs de chaque règle.
 *
 * @param {Array} rules Définitions des règles (GET /alert-rules).
 */
export function allForm( rules = [] ) {
	return {
		layout: { type: 'regular' },
		fields: [
			...SCAN_FORM.fields,
			...MENU_FORM.fields,
			...INTEGRATIONS_FORM.fields,
			...RETENTION_FORM.fields,
			...rules.flatMap( ( rule ) => ruleForm( rule ).fields ),
		],
	};
}

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
 * différents ne s'écrasent pas. Une règle modifiée est envoyée entière (activée, gravité, paramètres).
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @param {Array}  rules   Définitions des règles (GET /alert-rules).
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function changes( saved, current, rules = [] ) {
	const patch = {};
	SCAN_KEYS.forEach( ( key ) => {
		if ( ! same( saved.scan?.[ key ], current.scan?.[ key ] ) ) {
			patch.scan = { ...patch.scan, [ key ]: current.scan?.[ key ] };
		}
	} );
	if ( !! saved.sites_menu?.enabled !== !! current.sites_menu?.enabled ) {
		patch.sites_menu = { enabled: !! current.sites_menu?.enabled };
	}
	if (
		!! saved.integrations?.mcp_public !==
		!! current.integrations?.mcp_public
	) {
		patch.integrations = {
			mcp_public: !! current.integrations?.mcp_public,
		};
	}
	[ 'events_days', 'snapshots_days' ].forEach( ( key ) => {
		if ( ! same( saved.retention?.[ key ], current.retention?.[ key ] ) ) {
			patch.retention = {
				...patch.retention,
				[ key ]: current.retention?.[ key ],
			};
		}
	} );
	rules.forEach( ( rule ) => {
		const after = ruleConfig( current, rule );
		if ( ! same( ruleConfig( saved, rule ), after ) ) {
			patch.alerts = {
				rules: { ...patch.alerts?.rules, [ rule.id ]: after },
			};
		}
	} );
	return patch;
}
