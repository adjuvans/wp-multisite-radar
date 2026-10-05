import { beforeAll, expect, test } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import {
	displayUrl,
	formatBytes,
	formatDay,
	formatDisk,
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

test( 'a disk measure cut short by its time budget is a minimum', () => {
	expect( formatDisk( 2048 ) ).toBe( '2 KB' );
	expect( formatDisk( 2048, false ) ).toBe( '2 KB' );
	expect( formatDisk( 2048, true ) ).toBe( 'at least 2 KB' );
	expect( formatDisk( null, true ) ).toBe( '—' );
} );

test( 'a snapshot day keeps its calendar date in a timezone behind UTC', () => {
	const previous = getSettings();
	setSettings( {
		...previous,
		formats: { ...previous.formats, date: 'Y-m-d' },
		timezone: { ...previous.timezone, offset: -10, string: '' },
	} );

	try {
		expect( formatDay( '2026-09-01' ) ).toBe( '2026-09-01' );
	} finally {
		setSettings( previous );
	}
} );

test( 'a missing day is shown as a dash', () => {
	expect( formatDay( null ) ).toBe( '—' );
} );
