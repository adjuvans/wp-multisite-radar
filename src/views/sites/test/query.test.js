import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	DEFAULT_FIELDS,
	fromSitesView,
	parseSitesQuery,
	serializeSitesState,
	sitesPath,
	sitesPrefsFromView,
	sitesRestArgs,
	toSitesView,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'sites' ) )(
	'$name',
	( { query, prefs, args } ) => {
		test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
			expect( sitesRestArgs( parseSitesQuery( query ), prefs ) ).toEqual(
				args
			);
		} );
	}
);

describe( 'sites view state', () => {
	const state = parseSitesQuery( {
		s: 'blog',
		orderby: 'last_activity',
		order: 'desc',
		paged: '2',
		alert_level: 'error',
		status: 'archived,spam',
		rule: 'no_users',
		layout: 'grid',
		site: '7',
	} );

	test( 'the path is canonical', () => {
		expect( sitesPath( state, { sites: { per_page: 50 } } ) ).toBe(
			'/multisite-radar/v1/sites?alert_level=error&order=desc&orderby=last_activity&page=2&per_page=50&rule=no_users&search=blog&status=archived%2Cspam'
		);
	} );

	test( 'the DataViews view reflects the state and the preferences', () => {
		const view = toSitesView( state, {
			fields: [ 'theme' ],
			layout: 'table',
			per_page: 50,
		} );

		expect( view ).toMatchObject( {
			type: 'grid',
			search: 'blog',
			page: 2,
			perPage: 50,
			sort: { field: 'last_activity_gmt', direction: 'desc' },
			titleField: 'name',
			fields: [ 'theme' ],
		} );
		expect( view.filters ).toEqual( [
			{ field: 'alert_level', operator: 'isAny', value: [ 'error' ] },
			{
				field: 'status',
				operator: 'isAny',
				value: [ 'archived', 'spam' ],
			},
			{ field: 'rule', operator: 'is', value: 'no_users' },
		] );
		expect( toSitesView( parseSitesQuery( {} ), null ).fields ).toEqual(
			DEFAULT_FIELDS
		);
	} );

	test( 'a DataViews change maps back to the state, keeping the open site', () => {
		const next = fromSitesView(
			{
				type: 'table',
				search: '  rh ',
				page: 1,
				perPage: 20,
				sort: { field: 'users_count', direction: 'asc' },
				filters: [
					{
						field: 'registry_status',
						operator: 'isAny',
						value: [ 'missing', 'fresh' ],
					},
					{ field: 'rule', operator: 'is', value: 'Bad Rule' },
				],
				fields: [ 'theme', 'users_count' ],
			},
			state
		);

		expect( next ).toEqual( {
			search: 'rh',
			page: 1,
			orderby: 'users_count',
			order: 'asc',
			alert_level: [],
			status: [],
			registry_status: [ 'fresh', 'missing' ],
			rule: '',
			layout: 'table',
			site: 7,
		} );
	} );

	test( 'the address only keeps what differs from the defaults', () => {
		expect( serializeSitesState( parseSitesQuery( {} ) ) ).toEqual( {} );
		expect( serializeSitesState( state ) ).toEqual( {
			s: 'blog',
			paged: '2',
			orderby: 'last_activity',
			order: 'desc',
			alert_level: 'error',
			status: 'archived,spam',
			rule: 'no_users',
			layout: 'grid',
			site: '7',
		} );
	} );

	test( 'preferences come from the view', () => {
		expect(
			sitesPrefsFromView( {
				type: 'grid',
				perPage: 50,
				fields: [ 'theme' ],
			} )
		).toEqual( {
			fields: [ 'theme' ],
			layout: 'grid',
			per_page: 50,
		} );
		expect( sitesPrefsFromView( { type: 'list', perPage: 7 } ) ).toEqual( {
			fields: [],
			layout: 'table',
			per_page: 20,
		} );
	} );
} );
