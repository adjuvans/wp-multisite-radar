import { expect, test } from 'vitest';
import { idsToTokens, tokenLabel, tokensToIds } from '../tokens';

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
