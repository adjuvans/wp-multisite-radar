import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	fromUsersView,
	parseUsersQuery,
	serializeUsersState,
	toUsersView,
	usersPath,
	usersPrefsFromView,
	usersRestArgs,
} from '../query';

describe.each( cases.filter( ( item ) => item.view === 'users' ) )(
	'$name',
	( { query, prefs, args } ) => {
		test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
			expect( usersRestArgs( parseUsersQuery( query ), prefs ) ).toEqual(
				args
			);
		} );
	}
);

describe( 'users view state', () => {
	const state = parseUsersQuery( {
		s: 'jo',
		membership: 'none',
		super_admin: '1',
		orderby: 'registered',
		order: 'desc',
	} );

	test( 'round-trips through the address and the DataViews view', () => {
		expect( parseUsersQuery( serializeUsersState( state ) ) ).toEqual(
			state
		);
		const view = toUsersView( state, { fields: [], per_page: 50 } );
		expect( view.sort ).toEqual( {
			field: 'registered_gmt',
			direction: 'desc',
		} );
		expect( view.filters ).toEqual( [
			{ field: 'sites_count', operator: 'is', value: 'none' },
			{ field: 'super_admin', operator: 'is', value: 'yes' },
		] );
		expect( fromUsersView( view, state ) ).toEqual( state );
		expect( usersPrefsFromView( view ) ).toEqual( {
			fields: [
				'display_name',
				'super_admin',
				'sites_count',
				'registered_gmt',
			],
			per_page: 50,
		} );
	} );

	test( 'the REST path carries the filters', () => {
		expect( usersPath( state, { users: { per_page: 20 } } ) ).toBe(
			'/multisite-radar/v1/users?membership=none&order=desc&orderby=registered&page=1&per_page=20&search=jo&super_admin=1'
		);
	} );
} );
