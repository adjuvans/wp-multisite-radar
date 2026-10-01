import { beforeEach, expect, test, vi } from 'vitest';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import { mergeDeep, toPayload } from '../fields';
import SettingsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const SETTINGS = {
	scan: {
		activity_post_types: [ 'post', 'page' ],
		analysis_plugins: [],
		measure_disk: true,
		full_rescan_days: 7,
	},
	alerts: { rules: {} },
	reports: {
		digest_enabled: false,
		digest_day: 1,
		digest_recipients: { mode: 'super_admins', emails: [] },
	},
	integrations: { mcp_public: false },
	sites_menu: { enabled: false },
	retention: { events_days: 90, snapshots_days: 365 },
};

function setup() {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/settings': { body: SETTINGS, headers: {} },
	};
	window.msradarAdmin = {
		view: 'settings',
		canManage: true,
		preload,
		postTypes: [
			{ value: 'post', label: 'Posts' },
			{ value: 'page', label: 'Pages' },
		],
		plugins: [ { value: 'msradar-demo-cpt', label: 'Demo CPT' } ],
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<SettingsView />
		</RegistryProvider>
	);
	return registry;
}

function notices( registry ) {
	return registry
		.select( noticesStore )
		.getNotices()
		.map( ( notice ) => notice.content );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

test( 'helpers merge nested edits and send only the sections of this screen', () => {
	const merged = mergeDeep( SETTINGS, {
		scan: { full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );

	expect( merged.scan ).toEqual( { ...SETTINGS.scan, full_rescan_days: 14 } );
	expect( toPayload( merged ) ).toEqual( {
		scan: {
			activity_post_types: [ 'post', 'page' ],
			analysis_plugins: [],
			full_rescan_days: 14,
		},
		sites_menu: { enabled: true },
	} );
} );

test( 'shows the preloaded settings and saves a change', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		sites_menu: { enabled: true },
	} );
	const registry = setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	expect( save ).toBeDisabled();

	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	await act( async () => {
		fireEvent.click( save );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/settings',
		method: 'POST',
		data: {
			scan: {
				activity_post_types: [ 'post', 'page' ],
				analysis_plugins: [],
				full_rescan_days: 7,
			},
			sites_menu: { enabled: true },
		},
	} );
	await waitFor( () =>
		expect( notices( registry ) ).toContain( 'Settings saved.' )
	);
} );

test( 'a rejected save is reported', async () => {
	apiFetch.mockRejectedValue( {
		code: 'msradar_invalid_settings',
		message:
			'settings[scan][full_rescan_days] must be between 1 (inclusive) and 90 (inclusive)',
	} );
	const registry = setup();

	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	await waitFor( () =>
		expect( notices( registry ) ).toContain(
			'settings[scan][full_rescan_days] must be between 1 (inclusive) and 90 (inclusive)'
		)
	);
} );

test( 'an out-of-range number of days disables saving', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );

	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	expect( save ).toBeEnabled();

	fireEvent.change(
		screen.getByRole( 'spinbutton', { name: /Full analysis every/ } ),
		{ target: { value: '0' } }
	);

	await waitFor( () => expect( save ).toBeDisabled() );
} );
