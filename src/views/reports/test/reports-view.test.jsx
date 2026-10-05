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
import ReportsView from '..';
import { digestChanges } from '../digest-fields';

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

const TRENDS = {
	days: 90,
	since: '2026-06-19',
	site: null,
	points: [
		{
			day: '2026-09-15',
			sites: 3,
			content_count: 10,
			media_count: 4,
			alerts_error: 1,
			alerts_warning: 0,
			alerts_info: 1,
		},
		{
			day: '2026-09-16',
			sites: 4,
			content_count: 12,
			media_count: 4,
			alerts_error: 0,
			alerts_warning: 1,
			alerts_info: 1,
		},
	],
};

function setup( { canManage = true } = {} ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/reports/trends?days=90': {
			body: TRENDS,
			headers: {},
		},
		'/multisite-radar/v1/events?page=1&per_page=20': {
			body: [],
			headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
		},
		...( canManage
			? {
					'/multisite-radar/v1/settings': {
						body: SETTINGS,
						headers: {},
					},
				}
			: {} ),
	};
	window.msradarAdmin = { view: 'reports', canManage, preload, pages: {} };
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<ReportsView />
		</RegistryProvider>
	);
	return registry;
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

test( 'shows the trends of the network from the preloaded data', () => {
	setup();

	expect(
		screen.getByRole( 'img', {
			name: /^Sites, from .+ Latest: Sites: 4\.$/,
		} )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'img', { name: /Sites by highest alert/ } )
	).toBeInTheDocument();
	expect( screen.getByText( 'No change recorded yet.' ) ).toBeInTheDocument();
} );

test( 'the events filter keeps its own id next to the digest form', () => {
	setup();

	expect(
		screen.getByRole( 'combobox', { name: 'Kind of change' } ).id
	).toMatch( /^msradar-events-type-/ );
	expect(
		screen.getByRole( 'checkbox', {
			name: /Send a weekly summary by e-mail/,
		} )
	).toBeInTheDocument();
} );

test( 'another period asks for its trends', async () => {
	setup();

	fireEvent.change( screen.getByRole( 'combobox', { name: 'Period' } ), {
		target: { value: '30' },
	} );

	await waitFor( () => {
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/multisite-radar/v1/reports/trends?days=30',
			} )
		);
	} );
} );

test( 'only the changed digest settings are saved', () => {
	const on = {
		...SETTINGS,
		reports: { ...SETTINGS.reports, digest_enabled: true },
	};

	expect( digestChanges( SETTINGS, on ) ).toEqual( {
		reports: { digest_enabled: true },
	} );
	expect( digestChanges( SETTINGS, SETTINGS ) ).toEqual( {} );
	expect(
		digestChanges( SETTINGS, {
			...SETTINGS,
			reports: {
				...SETTINGS.reports,
				digest_recipients: {
					mode: 'custom',
					emails: [ 'a@example.org' ],
				},
			},
		} )
	).toEqual( {
		reports: {
			digest_recipients: { mode: 'custom', emails: [ 'a@example.org' ] },
		},
	} );
} );

test( 'the digest is switched on and saved', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		reports: { ...SETTINGS.reports, digest_enabled: true },
	} );
	setup();

	fireEvent.click(
		screen.getByRole( 'checkbox', {
			name: /Send a weekly summary by e-mail/,
		} )
	);
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/settings',
		method: 'POST',
		data: { reports: { digest_enabled: true } },
	} );
} );

test( 'a test e-mail is sent on request', async () => {
	apiFetch.mockResolvedValue( { sent: true } );
	const registry = setup();

	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Send a test e-mail to me' } )
		);
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/reports/digest/test',
		method: 'POST',
	} );
	expect(
		registry
			.select( noticesStore )
			.getNotices()
			.map( ( notice ) => notice.content )
	).toContain( 'The test e-mail was sent to your address.' );
} );

test( 'the digest settings are reserved to managers', () => {
	setup( { canManage: false } );

	expect(
		screen.queryByRole( 'button', { name: 'Send a test e-mail to me' } )
	).toBeNull();
} );

test( 'a failed test e-mail shows the error message', async () => {
	apiFetch.mockRejectedValue( {
		code: 'msradar_mail_failed',
		message: 'The e-mail could not be sent.',
	} );
	const registry = setup();

	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Send a test e-mail to me' } )
		);
	} );

	expect(
		registry
			.select( noticesStore )
			.getNotices()
			.map( ( notice ) => notice.content )
	).toContain( 'The e-mail could not be sent.' );
} );

test( 'a failed test e-mail without message shows a fallback text', async () => {
	apiFetch.mockRejectedValue( {} );
	const registry = setup();

	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Send a test e-mail to me' } )
		);
	} );

	expect(
		registry
			.select( noticesStore )
			.getNotices()
			.map( ( notice ) => notice.content )
	).toContain( 'The test e-mail could not be sent.' );
} );
