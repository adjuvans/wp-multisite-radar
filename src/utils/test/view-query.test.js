import { expect, test } from 'vitest';
import { page, perPage, samePrefs, subset, trimAscii } from '../view-query';

test( 'trimAscii strips exactly the characters of PHP trim()', () => {
	expect( trimAscii( ' \t\n\r\0\x0B blog \x0B\0\r\n\t ' ) ).toBe( 'blog' );
	expect( trimAscii( '\x0Bblog\0' ) ).toBe( 'blog' );
	expect( trimAscii( ' blog ' ) ).toBe( ' blog ' );
	expect( trimAscii( ' blog﻿' ) ).toBe( ' blog﻿' );
	expect( trimAscii( 'a b' ) ).toBe( 'a b' );
} );

test( 'page, perPage and subset follow the PHP rules', () => {
	expect( page( { paged: '1e3' } ) ).toBe( 1 );
	expect( page( { paged: '0' } ) ).toBe( 1 );
	expect( page( { paged: '99999999999999999999' } ) ).toBe( 100000 );
	expect( perPage( 50 ) ).toBe( 50 );
	expect( perPage( '50' ) ).toBe( 20 );
	expect( subset( { k: 'c, a ,x' }, 'k', [ 'a', 'b', 'c' ] ) ).toEqual( [
		'a',
		'c',
	] );
} );

test( 'samePrefs compares layout, per_page and fields', () => {
	const prefs = { layout: 'table', per_page: 20, fields: [ 'theme' ] };

	expect( samePrefs( prefs, { ...prefs, fields: [ 'theme' ] } ) ).toBe(
		true
	);
	expect( samePrefs( prefs, { ...prefs, fields: [] } ) ).toBe( false );
	expect( samePrefs( prefs, { ...prefs, layout: 'grid' } ) ).toBe( false );
	expect( samePrefs( prefs, { ...prefs, per_page: 50 } ) ).toBe( false );
	expect(
		samePrefs( { per_page: 20, fields: [] }, { per_page: 20, fields: [] } )
	).toBe( true );
	expect( samePrefs( prefs, null ) ).toBe( false );
} );
