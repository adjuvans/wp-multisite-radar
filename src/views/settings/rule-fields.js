import { __, sprintf } from '@wordpress/i18n';
import { severityLabels } from '../../components/badges';

const SEVERITIES = [ 'error', 'warning', 'info' ];

function isObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

/**
 * Réglage effectif d'une règle : valeurs enregistrées, sinon celles de la règle (GET /alert-rules). Comme en PHP,
 * severity null signifie « gravité par défaut de la règle ».
 *
 * @param {Object} settings Réglages (msradar_settings).
 * @param {Object} rule     Définition de la règle.
 * @return {{enabled: boolean, severity: ?string, params: Object}} Réglage de la règle.
 */
export function ruleConfig( settings, rule ) {
	const stored = settings?.alerts?.rules?.[ rule.id ];
	const config = isObject( stored ) ? stored : {};
	let storedParams = isObject( config.params ) ? config.params : {};
	// Un schéma fermé refuse (400) un paramètre qu'il ne déclare plus, par exemple renommé : on ne le renvoie pas.
	if ( rule.params_schema?.additionalProperties === false ) {
		const declared = isObject( rule.params_schema.properties )
			? rule.params_schema.properties
			: {};
		storedParams = Object.fromEntries(
			Object.entries( storedParams ).filter(
				( [ key ] ) => key in declared
			)
		);
	}
	return {
		enabled: config.enabled ?? true,
		severity: config.severity ?? null,
		params: {
			...( isObject( rule.default_params ) ? rule.default_params : {} ),
			...storedParams,
		},
	};
}

function change( rule, value ) {
	return { alerts: { rules: { [ rule.id ]: value } } };
}

/**
 * Champ d'un paramètre, d'après son schéma JSON. Un type que le formulaire ne sait pas modifier ne donne pas de
 * champ : le paramètre garde sa valeur enregistrée, renvoyée telle quelle avec la règle.
 *
 * @param {Object} rule   Définition de la règle.
 * @param {string} key    Nom du paramètre.
 * @param {Object} schema Schéma du paramètre.
 */
function paramField( rule, key, schema ) {
	const field = {
		id: `alerts.rules.${ rule.id }.params.${ key }`,
		label: schema.title || key,
		description: schema.description,
		getValue: ( { item } ) => ruleConfig( item, rule ).params[ key ],
		setValue: ( { value } ) =>
			change( rule, { params: { [ key ]: value } } ),
	};
	if ( schema.type === 'integer' || schema.type === 'number' ) {
		// Obligatoire seulement si le schéma l'exige ou si la règle fournit une valeur : un paramètre tiers sans valeur
		// par défaut ne doit pas bloquer l'enregistrement (le serveur l'accepte absent).
		const requiredList = rule.params_schema?.required;
		const hasDefault =
			isObject( rule.default_params ) && key in rule.default_params;
		const isValid = {
			required:
				schema.required === true ||
				( Array.isArray( requiredList ) &&
					requiredList.includes( key ) ) ||
				hasDefault,
		};
		if ( typeof schema.minimum === 'number' ) {
			isValid.min = schema.minimum;
		}
		if ( typeof schema.maximum === 'number' ) {
			isValid.max = schema.maximum;
		}
		return { ...field, type: schema.type, isValid };
	}
	if ( schema.type === 'boolean' ) {
		return { ...field, type: 'boolean', Edit: 'toggle' };
	}
	if ( schema.type === 'string' && Array.isArray( schema.enum ) ) {
		return {
			...field,
			type: 'text',
			elements: schema.enum.map( ( value ) => ( {
				value,
				label: String( value ),
			} ) ),
		};
	}
	return null;
}

/**
 * Champs DataForm d'une règle : activée, gravité, puis ses paramètres.
 *
 * @param {Object} rule Définition de la règle (GET /alert-rules).
 */
export function getRuleFields( rule ) {
	const severities = severityLabels();
	const properties = isObject( rule.params_schema?.properties )
		? rule.params_schema.properties
		: {};
	return [
		{
			id: `alerts.rules.${ rule.id }.enabled`,
			type: 'boolean',
			label: __( 'Enabled', 'multisite-radar' ),
			Edit: 'toggle',
			getValue: ( { item } ) => ruleConfig( item, rule ).enabled,
			setValue: ( { value } ) => change( rule, { enabled: !! value } ),
		},
		{
			id: `alerts.rules.${ rule.id }.severity`,
			type: 'text',
			label: __( 'Severity', 'multisite-radar' ),
			elements: [
				{
					value: '',
					label: sprintf(
						/* translators: %s: default severity of the rule, such as "Warning". */
						__( 'Default (%s)', 'multisite-radar' ),
						severities[ rule.default_severity ] ||
							rule.default_severity
					),
				},
				...SEVERITIES.map( ( value ) => ( {
					value,
					label: severities[ value ],
				} ) ),
			],
			getValue: ( { item } ) => ruleConfig( item, rule ).severity || '',
			setValue: ( { value } ) =>
				change( rule, { severity: value || null } ),
		},
		...Object.entries( properties )
			.map( ( [ key, schema ] ) =>
				paramField( rule, key, isObject( schema ) ? schema : {} )
			)
			.filter( Boolean ),
	];
}

export function ruleForm( rule ) {
	return {
		layout: { type: 'regular' },
		fields: getRuleFields( rule ).map( ( field ) => field.id ),
	};
}
