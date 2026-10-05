import { beforeEach, expect, test, vi } from 'vitest';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore, STORE_NAME } from '../../../store';
import { changes, mergeDeep } from '../fields';
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

const RULES = [
	{
		id: 'no_users',
		label: 'Site without users',
		description: 'No user account is attached to the site.',
		default_severity: 'error',
		default_params: {},
		params_schema: { type: 'object', properties: {} },
	},
	{
		id: 'inactive',
		label: 'Inactive site',
		description:
			'No content of the tracked types has been published or updated for a while.',
		default_severity: 'warning',
		default_params: { months: 6 },
		params_schema: {
			type: 'object',
			properties: {
				months: {
					type: 'integer',
					minimum: 1,
					maximum: 120,
					title: 'Months without activity',
					description:
						'Months without activity before the alert is raised.',
				},
			},
		},
	},
	{
		id: 'acme_limit',
		label: 'Acme limit',
		description: 'A rule with an optional number.',
		default_severity: 'info',
		default_params: {},
		params_schema: {
			type: 'object',
			properties: { limit: { type: 'integer', minimum: 1 } },
		},
	},
	{
		id: 'acme_rule',
		label: 'Acme rule',
		description: 'A rule added by another plugin.',
		default_severity: 'info',
		default_params: { tags: [ 'a' ] },
		params_schema: {
			type: 'object',
			properties: { tags: { type: 'array' } },
		},
	},
];

/**
 * Panneau d'une règle, ouvert.
 *
 * @param {string} label Libellé de la règle.
 */
function openRule( label ) {
	const toggle = screen.getByRole( 'button', { name: label } );
	fireEvent.click( toggle );
	return toggle.closest( '.components-panel__body' );
}

function setup(
	settings = SETTINGS,
	extraPreload = {},
	{ rules = true } = {}
) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/settings': { body: settings, headers: {} },
		...( rules
			? {
					'/multisite-radar/v1/alert-rules': {
						body: RULES,
						headers: {},
					},
				}
			: {} ),
		...extraPreload,
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

test( 'only the values that differ from the saved settings are sent', () => {
	const current = mergeDeep( SETTINGS, {
		scan: { full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );

	expect( current.scan ).toEqual( {
		...SETTINGS.scan,
		full_rescan_days: 14,
	} );
	expect( changes( SETTINGS, current ) ).toEqual( {
		scan: { full_rescan_days: 14 },
		sites_menu: { enabled: true },
	} );
	expect(
		changes(
			SETTINGS,
			mergeDeep( current, {
				scan: { full_rescan_days: 7 },
				sites_menu: { enabled: false },
			} )
		)
	).toEqual( {} );
} );

test( 'shows the preloaded settings and saves a change', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		sites_menu: { enabled: true },
	} );
	const SITES = '/multisite-radar/v1/sites?page=1';
	const registry = setup( SETTINGS, {
		[ SITES ]: { body: [], headers: {} },
	} );
	expect( registry.select( STORE_NAME ).getResponse( SITES ) ).not.toBeNull();
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
			sites_menu: { enabled: true },
		},
	} );
	await waitFor( () =>
		expect( notices( registry ) ).toContain( 'Settings saved.' )
	);
	const stored = registry
		.select( STORE_NAME )
		.getResponse( '/multisite-radar/v1/settings' );
	expect( stored.data.sites_menu ).toEqual( { enabled: true } );
	expect( registry.select( STORE_NAME ).getResponse( SITES ) ).toBeNull();
	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'a rejected save is reported', async () => {
	apiFetch.mockRejectedValue( {
		code: 'msradar_invalid_settings',
		message:
			'settings[scan][full_rescan_days] must be between 1 (inclusive) and 90 (inclusive)',
	} );
	const registry = setup();
	const toggle = screen.getByRole( 'checkbox', {
		name: /network sites menu/,
	} );

	fireEvent.click( toggle );
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
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	expect( toggle ).toBeChecked();
	await waitFor( () => expect( save ).toBeEnabled() );
	expect( save ).not.toHaveAttribute( 'aria-busy', 'true' );
} );

test( 'a stored plugin slug that is no longer installed does not block saving', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup( {
		...SETTINGS,
		scan: { ...SETTINGS.scan, analysis_plugins: [ 'gone' ] },
	} );
	const save = screen.getByRole( 'button', { name: 'Save settings' } );

	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	await waitFor( () => expect( save ).toBeEnabled() );
	await act( async () => {
		fireEvent.click( save );
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		sites_menu: { enabled: true },
	} );
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

test( 'undoing a change leaves nothing to save', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const toggle = screen.getByRole( 'checkbox', {
		name: /network sites menu/,
	} );

	fireEvent.click( toggle );
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.click( toggle );

	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'the disk measure can be switched off', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		scan: { ...SETTINGS.scan, measure_disk: false },
	} );
	setup();
	const toggle = screen.getByRole( 'checkbox', {
		name: /Measure the disk space used by each site/,
	} );
	expect( toggle ).toBeChecked();

	fireEvent.click( toggle );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		scan: { measure_disk: false },
	} );
} );

test( 'each rule has a panel, and a changed parameter is saved with its rule', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup();
	expect(
		screen.getByRole( 'button', { name: 'Site without users' } )
	).toBeInTheDocument();
	const panel = openRule( 'Inactive site' );
	const months = within( panel ).getByRole( 'spinbutton', {
		name: /Months without activity/,
	} );
	expect( months ).toHaveValue( 6 );

	fireEvent.change( months, { target: { value: '8' } } );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		alerts: {
			rules: {
				inactive: {
					enabled: true,
					severity: null,
					params: { months: 8 },
				},
			},
		},
	} );
} );

test( 'a disabled rule says so, and choosing the default severity again leaves nothing to save', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const panel = openRule( 'Site without users' );

	fireEvent.click(
		within( panel ).getByRole( 'checkbox', { name: 'Enabled' } )
	);
	expect(
		screen.getByRole( 'button', { name: 'Site without users (disabled)' } )
	).toBeInTheDocument();
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.click(
		within( panel ).getByRole( 'checkbox', { name: 'Enabled' } )
	);
	await waitFor( () => expect( save ).toBeDisabled() );

	const severity = within( panel ).getByRole( 'combobox', {
		name: 'Severity',
	} );
	expect( severity ).toHaveValue( '' );
	expect(
		within( severity ).getByRole( 'option', { name: 'Default (Error)' } )
	).toBeInTheDocument();
	fireEvent.change( severity, { target: { value: 'warning' } } );
	await waitFor( () => expect( save ).toBeEnabled() );
	fireEvent.change( severity, { target: { value: '' } } );
	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'a third-party rule keeps the parameters the form cannot edit', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup();
	const panel = openRule( 'Acme rule' );
	expect( within( panel ).queryByRole( 'spinbutton' ) ).toBeNull();

	fireEvent.change(
		within( panel ).getByRole( 'combobox', { name: 'Severity' } ),
		{
			target: { value: 'error' },
		}
	);
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		alerts: {
			rules: {
				acme_rule: {
					enabled: true,
					severity: 'error',
					params: { tags: [ 'a' ] },
				},
			},
		},
	} );
} );

test( 'an out-of-range parameter disables saving', async () => {
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	const panel = openRule( 'Inactive site' );

	fireEvent.change(
		within( panel ).getByRole( 'spinbutton', {
			name: /Months without activity/,
		} ),
		{ target: { value: '0' } }
	);

	await waitFor( () => expect( save ).toBeDisabled() );
} );

test( 'rules that are not loaded yet show a skeleton; the rest of the form works', async () => {
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	setup( SETTINGS, {}, { rules: false } );

	expect( screen.getByText( 'Loading alert rules…' ) ).toBeInTheDocument();
	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);
	await waitFor( () =>
		expect(
			screen.getByRole( 'button', { name: 'Save settings' } )
		).toBeEnabled()
	);
} );

test( 'a third-party numeric parameter without a default does not block saving', async () => {
	apiFetch.mockResolvedValue( SETTINGS );
	setup();
	const save = screen.getByRole( 'button', { name: 'Save settings' } );

	fireEvent.click(
		screen.getByRole( 'checkbox', { name: /network sites menu/ } )
	);

	await waitFor( () => expect( save ).toBeEnabled() );
} );

test( 'switching the MCP exposure is a change, and switching it back is not', () => {
	const on = mergeDeep( SETTINGS, { integrations: { mcp_public: true } } );

	expect( changes( SETTINGS, on, RULES ) ).toEqual( {
		integrations: { mcp_public: true },
	} );
	expect( changes( on, SETTINGS, RULES ) ).toEqual( {
		integrations: { mcp_public: false },
	} );
	expect( changes( SETTINGS, mergeDeep( on, SETTINGS ), RULES ) ).toEqual(
		{}
	);
} );

test( 'MCP exposure is off by default and can be switched on', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		integrations: { mcp_public: true },
	} );
	setup();
	const toggle = screen.getByRole( 'checkbox', {
		name: /Let AI assistants read the audit through MCP/,
	} );
	expect( toggle ).not.toBeChecked();

	fireEvent.click( toggle );
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		integrations: { mcp_public: true },
	} );
} );

test( 'the retention of the history is saved', async () => {
	apiFetch.mockResolvedValue( {
		...SETTINGS,
		retention: { ...SETTINGS.retention, events_days: 30 },
	} );
	setup();

	fireEvent.change(
		screen.getByRole( 'spinbutton', {
			name: /Keep the changes for \(days\)/,
		} ),
		{ target: { value: '30' } }
	);
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
	} );

	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		retention: { events_days: 30 },
	} );
} );
