import { expect, test } from 'vitest';
import {
	idsToTokens,
	mergeSites,
	sitesPath,
	tokenLabel,
	tokensToIds,
} from '../tokens';

const SITES = [
	{ id: 1, name: 'Main' },
	{ id: 12, name: 'Blog RH' },
	{ id: 13, name: '' },
];

test( 'sites are shown as "name (#id)" tokens and read back as IDs', () => {
	expect( tokenLabel( SITES[ 1 ] ) ).toBe( 'Blog RH (#12)' );
	expect( tokenLabel( SITES[ 2 ] ) ).toBe( '#13 (#13)' );
	expect( idsToTokens( [ 12, 99 ], SITES ) ).toEqual( [
		'Blog RH (#12)',
		'#99',
	] );
	expect(
		tokensToIds(
			[ 'Blog RH (#12)', 'Main', '#99', { value: 'Main' }, 'Unknown' ],
			SITES
		)
	).toEqual( [ 12, 1, 99 ] );
} );

test( 'an exact site name wins over the #id pattern', () => {
	const sites = [ ...SITES, { id: 7, name: 'Team #5' } ];
	expect( tokensToIds( [ 'Team #5' ], sites ) ).toEqual( [ 7 ] );
	expect( tokensToIds( [ '#5' ], sites ) ).toEqual( [ 5 ] );
} );

test( 'the sites are searched by name, or read back by ID', () => {
	expect( sitesPath() ).toBe( '/multisite-radar/v1/sites-menu/sites' );
	expect( sitesPath( { search: 'Blog RH' } ) ).toBe(
		'/multisite-radar/v1/sites-menu/sites?search=Blog%20RH'
	);
	expect( sitesPath( { include: [ 12, 3 ] } ) ).toBe(
		'/multisite-radar/v1/sites-menu/sites?include=12%2C3'
	);
} );

test( 'known sites are merged by ID, the latest name winning', () => {
	expect(
		mergeSites(
			[
				{ id: 1, name: 'Main' },
				{ id: 12, name: 'Old name' },
			],
			[
				{ id: 12, name: 'Blog RH' },
				{ id: 20, name: 'Events' },
			]
		)
	).toEqual( [
		{ id: 1, name: 'Main' },
		{ id: 12, name: 'Blog RH' },
		{ id: 20, name: 'Events' },
	] );
} );
