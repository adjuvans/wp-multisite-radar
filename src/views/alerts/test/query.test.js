import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	alertsPath,
	alertsPrefsFromView,
	alertsRestArgs,
	fromAlertsView,
	parseAlertsQuery,
	serializeAlertsState,
	toAlertsView,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'alerts' ) )(
	'$name',
	( { query, prefs, args } ) => {
		test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
			expect(
				alertsRestArgs( parseAlertsQuery( query ), prefs )
			).toEqual( args );
		} );
	}
);

describe( 'alerts view state', () => {
	const state = parseAlertsQuery( {
		severity: 'warning,error',
		rule: 'no_users',
		orderby: 'rule',
	} );

	test( 'path, grouping and filters', () => {
		expect( alertsPath( state, {} ) ).toBe(
			'/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20&rule=no_users&severity=error%2Cwarning'
		);
		const view = toAlertsView( state, { fields: [], per_page: 20 } );
		expect( view.groupBy ).toEqual( { field: 'rule' } );
		expect( view.sort ).toEqual( { field: 'rule', direction: 'asc' } );
		expect( view.filters ).toEqual( [
			{
				field: 'severity',
				operator: 'isAny',
				value: [ 'error', 'warning' ],
			},
			{ field: 'rule', operator: 'isAny', value: [ 'no_users' ] },
		] );
		expect(
			toAlertsView( { ...state, orderby: 'name' }, null ).groupBy
		).toBeUndefined();
	} );

	test( 'a DataViews change maps back to the state and to the preferences', () => {
		const view = {
			search: ' rh ',
			page: 2,
			perPage: 50,
			sort: { field: 'site', direction: 'desc' },
			filters: [
				{
					field: 'rule',
					operator: 'isAny',
					value: [ 'inactive', 'Bad Rule', 'inactive' ],
				},
			],
			fields: [ 'message' ],
		};

		expect( fromAlertsView( view, state ) ).toEqual( {
			search: 'rh',
			page: 2,
			orderby: 'name',
			order: 'desc',
			severity: [],
			rule: [ 'inactive' ],
		} );
		expect( alertsPrefsFromView( view ) ).toEqual( {
			fields: [ 'message' ],
			per_page: 50,
		} );
		expect( serializeAlertsState( parseAlertsQuery( {} ) ) ).toEqual( {} );
	} );
} );
