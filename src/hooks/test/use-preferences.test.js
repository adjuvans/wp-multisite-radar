import { expect, test } from 'vitest';
import { DEFAULT_PREFERENCES, mergePreferences } from '../use-preferences';

test( 'corrupted stored preferences fall back to the defaults', () => {
	[ null, undefined, 'oops', 12 ].forEach( ( stored ) => {
		expect( mergePreferences( stored ) ).toEqual( DEFAULT_PREFERENCES );
	} );
	expect( mergePreferences( { sites: 'broken', alerts: null } ) ).toEqual(
		DEFAULT_PREFERENCES
	);
} );

test( 'partial preferences keep their values and default the rest, per view', () => {
	expect( mergePreferences( { sites: { per_page: 50 } } ) ).toEqual( {
		sites: { ...DEFAULT_PREFERENCES.sites, per_page: 50 },
		alerts: DEFAULT_PREFERENCES.alerts,
	} );
} );
