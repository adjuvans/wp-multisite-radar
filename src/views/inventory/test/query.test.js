import { describe, expect, test } from 'vitest';
import cases from '../../../../tests/fixtures/view-queries.json';
import {
	extensionSitesPath,
	fromInventoryView,
	INVENTORY_STATUSES,
	inventoryExportArgs,
	inventoryPath,
	inventoryPrefsFromView,
	inventoryRestArgs,
	parseInventoryQuery,
	serializeInventoryState,
	toInventoryView,
} from '../query';

describe.each(
	cases.filter(
		( item ) => item.view === 'plugins' || item.view === 'themes'
	)
)( '$name', ( { view, query, prefs, args } ) => {
	test( 'gives the REST arguments that PHP preloads (Admin\\ViewQuery)', () => {
		expect(
			inventoryRestArgs(
				parseInventoryQuery( query, INVENTORY_STATUSES[ view ] ),
				prefs?.[ view ]?.per_page
			)
		).toEqual( args );
	} );
} );

describe( 'inventory view state', () => {
	const statuses = INVENTORY_STATUSES.plugins;
	const state = parseInventoryQuery(
		{
			s: 'aki',
			status: 'missing,unused',
			has_update: '1',
			orderby: 'sites_count',
			order: 'desc',
			paged: '2',
		},
		statuses
	);

	test( 'round-trips through the address', () => {
		expect(
			parseInventoryQuery( serializeInventoryState( state ), statuses )
		).toEqual( state );
		expect(
			serializeInventoryState( parseInventoryQuery( {}, statuses ) )
		).toEqual( {} );
	} );

	test( 'round-trips through the DataViews view', () => {
		const view = toInventoryView(
			state,
			{ fields: [ 'status' ], per_page: 50 },
			[ 'version' ]
		);

		expect( view.filters ).toEqual( [
			{
				field: 'status',
				operator: 'isAny',
				value: [ 'unused', 'missing' ],
			},
			{ field: 'update_version', operator: 'is', value: 'available' },
		] );
		expect( view.fields ).toEqual( [ 'status' ] );
		expect( view.perPage ).toBe( 50 );
		expect( fromInventoryView( view, state, statuses ) ).toEqual( state );
		expect( inventoryPrefsFromView( view ) ).toEqual( {
			fields: [ 'status' ],
			per_page: 50,
		} );
	} );

	test( 'cleared filters and an unknown sort fall back to the defaults', () => {
		expect(
			fromInventoryView(
				{
					filters: [],
					sort: { field: 'file', direction: 'desc' },
					page: 1,
				},
				state,
				statuses
			)
		).toEqual( {
			search: '',
			page: 1,
			orderby: 'name',
			order: 'desc',
			status: [],
			has_update: false,
		} );
	} );

	test( 'paths of the list, the export and the sites of an extension', () => {
		expect( inventoryPath( 'plugins', state, { per_page: 50 } ) ).toBe(
			'/multisite-radar/v1/plugins?has_update=1&order=desc&orderby=sites_count&page=2&per_page=50&search=aki&status=unused%2Cmissing'
		);
		expect( inventoryExportArgs( state, [ 'name', 'file' ] ) ).toEqual( {
			orderby: 'sites_count',
			order: 'desc',
			search: 'aki',
			status: 'unused,missing',
			has_update: 1,
			fields: 'name,file',
		} );
		expect(
			extensionSitesPath( 'plugins', 'my plugin/my.plugin', {
				page: 1,
				per_page: 20,
			} )
		).toBe(
			'/multisite-radar/v1/plugins/my%20plugin/my.plugin/sites?page=1&per_page=20'
		);
	} );
} );
