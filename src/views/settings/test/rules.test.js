import { expect, test } from 'vitest';
import { changes } from '../fields';
import { getRuleFields, ruleConfig, ruleForm } from '../rule-fields';

const INACTIVE = {
	id: 'inactive',
	label: 'Inactive site',
	description:
		'No content of the tracked types has been published or updated for a while.',
	default_severity: 'warning',
	default_params: { months: 6 },
	params_schema: {
		type: 'object',
		properties: {
			months: {
				type: 'integer',
				minimum: 1,
				maximum: 120,
				title: 'Months without activity',
				description:
					'Months without activity before the alert is raised.',
			},
		},
	},
};
const NO_USERS = {
	id: 'no_users',
	label: 'Site without users',
	description: 'No user account is attached to the site.',
	default_severity: 'error',
	default_params: {},
	params_schema: { type: 'object', properties: {} },
};
const THIRD_PARTY = {
	id: 'acme_rule',
	label: 'Acme rule',
	description: 'A rule added by another plugin.',
	default_severity: 'info',
	default_params: { tags: [ 'a' ] },
	params_schema: { type: 'object', properties: { tags: { type: 'array' } } },
};

test( 'the effective configuration falls back on the defaults of the rule', () => {
	expect( ruleConfig( {}, INACTIVE ) ).toEqual( {
		enabled: true,
		severity: null,
		params: { months: 6 },
	} );
	expect(
		ruleConfig(
			{
				alerts: {
					rules: {
						inactive: {
							enabled: false,
							severity: 'error',
							params: { months: 9 },
						},
					},
				},
			},
			INACTIVE
		)
	).toEqual( { enabled: false, severity: 'error', params: { months: 9 } } );
	expect( ruleConfig( { alerts: { rules: [] } }, NO_USERS ) ).toEqual( {
		enabled: true,
		severity: null,
		params: {},
	} );
} );

test( 'fields: switch, severity with its default, then the parameters the form can edit', () => {
	expect( ruleForm( INACTIVE ).fields ).toEqual( [
		'alerts.rules.inactive.enabled',
		'alerts.rules.inactive.severity',
		'alerts.rules.inactive.params.months',
	] );
	expect( ruleForm( THIRD_PARTY ).fields ).toEqual( [
		'alerts.rules.acme_rule.enabled',
		'alerts.rules.acme_rule.severity',
	] );

	const [ enabled, severity, months ] = getRuleFields( INACTIVE );
	expect( enabled.setValue( { item: {}, value: false } ) ).toEqual( {
		alerts: { rules: { inactive: { enabled: false } } },
	} );
	expect( severity.elements[ 0 ] ).toEqual( {
		value: '',
		label: 'Default (Warning)',
	} );
	expect( severity.getValue( { item: {} } ) ).toBe( '' );
	expect( severity.setValue( { item: {}, value: '' } ) ).toEqual( {
		alerts: { rules: { inactive: { severity: null } } },
	} );
	expect( months.label ).toBe( 'Months without activity' );
	expect( months.isValid ).toEqual( { required: true, min: 1, max: 120 } );
	expect( months.getValue( { item: {} } ) ).toBe( 6 );
} );

test( 'a changed rule is sent whole; an unchanged one is not sent', () => {
	const saved = {
		scan: {},
		sites_menu: {},
		alerts: { rules: { acme_rule: { params: { tags: [ 'a', 'b' ] } } } },
	};
	const current = {
		...saved,
		alerts: {
			rules: {
				...saved.alerts.rules,
				inactive: { params: { months: 8 } },
			},
		},
	};

	expect(
		changes( saved, current, [ INACTIVE, NO_USERS, THIRD_PARTY ] )
	).toEqual( {
		alerts: {
			rules: {
				inactive: {
					enabled: true,
					severity: null,
					params: { months: 8 },
				},
			},
		},
	} );
	expect(
		changes(
			saved,
			{
				...saved,
				alerts: {
					rules: {
						acme_rule: {
							params: { tags: [ 'a', 'b' ] },
							severity: 'error',
						},
					},
				},
			},
			[ THIRD_PARTY ]
		)
	).toEqual( {
		alerts: {
			rules: {
				acme_rule: {
					enabled: true,
					severity: 'error',
					params: { tags: [ 'a', 'b' ] },
				},
			},
		},
	} );
} );

const LIMITED = {
	id: 'acme_limit',
	label: 'Acme limit',
	description: 'A rule added by another plugin.',
	default_severity: 'info',
	default_params: {},
	params_schema: {
		type: 'object',
		properties: { limit: { type: 'integer', minimum: 1 } },
	},
};

test( 'a numeric parameter is required only when the schema or a default value asks for it', () => {
	const required = ( rule, key ) =>
		getRuleFields( rule ).find( ( { id } ) => id.endsWith( `.${ key }` ) )
			.isValid.required;

	expect( required( LIMITED, 'limit' ) ).toBe( false );
	expect( required( INACTIVE, 'months' ) ).toBe( true );
	expect(
		required(
			{
				...LIMITED,
				params_schema: {
					...LIMITED.params_schema,
					required: [ 'limit' ],
				},
			},
			'limit'
		)
	).toBe( true );
	expect( getRuleFields( LIMITED )[ 2 ].isValid.min ).toBe( 1 );
} );

test( 'a cleared optional parameter is not sent', () => {
	const cleared = {
		alerts: { rules: { acme_limit: { params: { limit: undefined } } } },
	};
	const patch = changes( {}, cleared, [ LIMITED ] );
	expect( JSON.parse( JSON.stringify( patch ) ) ).toEqual( {} );
} );

test( 'a stored parameter that a closed schema no longer declares is dropped', () => {
	const settings = {
		alerts: {
			rules: { inactive: { params: { months: 9, old_key: 1 } } },
		},
	};
	const closed = {
		...INACTIVE,
		params_schema: {
			...INACTIVE.params_schema,
			additionalProperties: false,
		},
	};

	expect( ruleConfig( settings, closed ).params ).toEqual( { months: 9 } );
	expect( ruleConfig( settings, INACTIVE ).params ).toEqual( {
		months: 9,
		old_key: 1,
	} );
} );
