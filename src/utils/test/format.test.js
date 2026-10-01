import { beforeAll, expect, test } from 'vitest';
import {
	displayUrl,
	formatBytes,
	formatNumber,
	formatRelative,
	parseGmt,
} from '../format';

beforeAll( () => {
	document.documentElement.lang = 'en-US';
} );

test( 'REST dates are read as UTC, with or without an offset', () => {
	expect( parseGmt( '2026-09-01T10:00:00' ).toISOString() ).toBe(
		'2026-09-01T10:00:00.000Z'
	);
	expect( parseGmt( '2026-09-01T12:00:00+02:00' ).toISOString() ).toBe(
		'2026-09-01T10:00:00.000Z'
	);
	expect( parseGmt( null ) ).toBeNull();
	expect( parseGmt( 'not a date' ) ).toBeNull();
} );

test( 'numbers and sizes', () => {
	expect( formatNumber( 12345 ) ).toBe( '12,345' );
	expect( formatNumber( null ) ).toBe( '—' );
	expect( formatBytes( 512 ) ).toBe( '512 B' );
	expect( formatBytes( 1536 ) ).toBe( '1.5 KB' );
	expect( formatBytes( 5 * 1024 ** 3 ) ).toBe( '5 GB' );
	expect( formatBytes( null ) ).toBe( '—' );
} );

test( 'relative dates and display URLs', () => {
	expect(
		formatRelative(
			'2026-09-01T10:00:00',
			new Date( '2026-09-04T10:00:00Z' )
		)
	).toBe( '3 days ago' );
	expect( formatRelative( null ) ).toBe( '—' );
	expect( displayUrl( 'https://example.test/rh/' ) ).toBe(
		'example.test/rh'
	);
} );
