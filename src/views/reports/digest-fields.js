import { __ } from '@wordpress/i18n';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function days() {
	return [
		__( 'Sunday', 'multisite-radar' ),
		__( 'Monday', 'multisite-radar' ),
		__( 'Tuesday', 'multisite-radar' ),
		__( 'Wednesday', 'multisite-radar' ),
		__( 'Thursday', 'multisite-radar' ),
		__( 'Friday', 'multisite-radar' ),
		__( 'Saturday', 'multisite-radar' ),
	];
}

/**
 * Champs DataForm du récapitulatif (réglages reports.*, spec §8).
 */
export function getDigestFields() {
	return [
		{
			id: 'reports.digest_enabled',
			type: 'boolean',
			label: __( 'Send a weekly summary by e-mail', 'multisite-radar' ),
			description: __(
				'New and resolved alerts and the other changes of the last seven days, with a link to Multisite Radar.',
				'multisite-radar'
			),
			Edit: 'toggle',
		},
		{
			id: 'reports.digest_day',
			type: 'integer',
			label: __( 'Day of the week', 'multisite-radar' ),
			elements: days().map( ( label, value ) => ( { value, label } ) ),
		},
		{
			id: 'reports.digest_recipients.mode',
			type: 'text',
			label: __( 'Recipients', 'multisite-radar' ),
			elements: [
				{
					value: 'super_admins',
					label: __( 'All super admins', 'multisite-radar' ),
				},
				{
					value: 'custom',
					label: __( 'The addresses below', 'multisite-radar' ),
				},
			],
		},
		{
			id: 'reports.digest_recipients.emails',
			type: 'array',
			label: __( 'E-mail addresses', 'multisite-radar' ),
			description: __(
				'Used when the recipients are the addresses below. Each address receives its own e-mail.',
				'multisite-radar'
			),
			isValid: {
				elements: false,
				custom: ( item ) =>
					( item.reports?.digest_recipients?.emails || [] ).every(
						( email ) => EMAIL.test( email )
					)
						? null
						: __(
								'Enter valid e-mail addresses.',
								'multisite-radar'
							),
			},
		},
	];
}

export const DIGEST_FORM = {
	layout: { type: 'regular' },
	fields: [
		'reports.digest_enabled',
		'reports.digest_day',
		'reports.digest_recipients.mode',
		'reports.digest_recipients.emails',
	],
};

function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Corps de POST /settings : seules les valeurs du récapitulatif qui diffèrent des réglages enregistrés. Les
 * destinataires (mode et adresses) sont envoyés ensemble.
 *
 * @param {Object} saved   Réglages enregistrés.
 * @param {Object} current Réglages affichés, modifications comprises.
 * @return {Object} Modifications ; un objet vide s'il n'y a rien à enregistrer.
 */
export function digestChanges( saved, current ) {
	const patch = {};
	[ 'digest_enabled', 'digest_day', 'digest_recipients' ].forEach(
		( key ) => {
			if ( ! same( saved.reports?.[ key ], current.reports?.[ key ] ) ) {
				patch.reports = {
					...patch.reports,
					[ key ]: current.reports?.[ key ],
				};
			}
		}
	);
	return patch;
}
