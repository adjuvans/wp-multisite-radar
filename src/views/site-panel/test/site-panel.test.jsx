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
import { createCoreStore } from '../../../store';
import SitePanel from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const DETAIL = {
	id: 12,
	name: 'Blog RH',
	url: 'https://example.test/rh/',
	admin_url: 'https://example.test/rh/wp-admin/',
	status: { public: true, archived: false, spam: false, deleted: false },
	theme: { stylesheet: 'tt5', template: 'tt5' },
	users_count: 3,
	admins_count: 1,
	content_count: 5,
	media_count: 2,
	disk_bytes: null,
	db_bytes: null,
	autoload_bytes: null,
	last_activity_gmt: '2026-09-01T10:00:00',
	alert_level: 'warning',
	alerts_count: 1,
	alert_rules: [ 'inactive' ],
	registry_status: 'fresh',
	pending: false,
	dirty: false,
	scanned_at_gmt: '2026-09-02T10:00:00',
	post_types: [
		{
			name: 'post',
			label: 'Posts',
			publish: 4,
			total: 5,
			origin: { kind: 'core', slug: '' },
			builtin: true,
			verified: true,
		},
		{
			name: 'demo_event',
			label: 'Demo events',
			publish: 1,
			total: 1,
			origin: { kind: 'plugin', slug: 'msradar-demo-cpt' },
			builtin: false,
			verified: false,
		},
	],
	taxonomies: [
		{
			name: 'category',
			label: 'Categories',
			count: 2,
			origin: { kind: 'core', slug: '' },
			builtin: true,
			verified: true,
		},
	],
	users: { by_role: { administrator: 1 }, privileged: [] },
	last_content: {
		id: 5,
		type: 'post',
		title: 'Hello',
		date_gmt: '2026-09-01T10:00:00',
	},
	options: {},
	alerts: [
		{
			rule: 'inactive',
			severity: 'warning',
			label: 'Inactive site',
			message: 'Inactive for 8 months',
		},
	],
	extensions: {
		plugins_local: [
			{
				file: 'msradar-demo-cpt/msradar-demo-cpt.php',
				name: 'Demo CPT',
				version: '1.0',
				installed: true,
			},
		],
		network_plugins_count: 1,
		theme: {
			stylesheet: 'tt5',
			template: 'tt5',
			name: 'Twenty Twenty-Five',
			version: '1.2',
			installed: true,
		},
	},
	scan_error: null,
};

const ITEMS = [
	{ id: 11, name: 'Alpha' },
	{ id: 12, name: 'Blog RH' },
	{ id: 13, name: 'Gamma' },
];

function setup( {
	siteId = 12,
	preload = { '/multisite-radar/v1/sites/12': { body: DETAIL, headers: {} } },
} = {} ) {
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	const props = { onNavigate: vi.fn(), onClose: vi.fn() };
	render(
		<RegistryProvider value={ registry }>
			<SitePanel siteId={ siteId } items={ ITEMS } { ...props } />
		</RegistryProvider>
	);
	return props;
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

test( 'shows the preloaded site with focus on its title', async () => {
	setup();

	const heading = screen.getByRole( 'heading', { name: 'Blog RH' } );
	expect( heading ).toHaveFocus();
	expect(
		screen.getByRole( 'dialog', { name: 'Blog RH' } )
	).toBeInTheDocument();
	expect( screen.getByRole( 'tab', { name: 'Summary' } ) ).toHaveAttribute(
		'aria-selected',
		'true'
	);
	expect( screen.getByText( 'Twenty Twenty-Five' ) ).toBeInTheDocument();
	await act( () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ) );
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'the number of administrators is pluralised', () => {
	setup( {
		siteId: 14,
		preload: {
			'/multisite-radar/v1/sites/12': { body: DETAIL, headers: {} },
			'/multisite-radar/v1/sites/14': {
				body: { ...DETAIL, id: 14, users_count: 4, admins_count: 2 },
				headers: {},
			},
		},
	} );
	expect(
		screen.getByText( '4, including 2 administrators' )
	).toBeInTheDocument();
} );

test( 'a single administrator is singular', () => {
	setup();

	expect(
		screen.getByText( '3, including 1 administrator' )
	).toBeInTheDocument();
} );

test( 'the content tab shows origins and flags unverified types', () => {
	setup();

	fireEvent.click( screen.getByRole( 'tab', { name: 'Content' } ) );

	expect(
		screen.getByText( 'Plugin: msradar-demo-cpt' )
	).toBeInTheDocument();
	expect( screen.getAllByText( 'Not verified' ) ).toHaveLength( 1 );
} );

test( 'previous, next and Escape', () => {
	const { onNavigate, onClose } = setup();

	fireEvent.click( screen.getByRole( 'button', { name: 'Next site' } ) );
	expect( onNavigate ).toHaveBeenCalledWith( 13 );
	fireEvent.click( screen.getByRole( 'button', { name: 'Previous site' } ) );
	expect( onNavigate ).toHaveBeenCalledWith( 11 );
	fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
	expect( onClose ).toHaveBeenCalledTimes( 1 );
} );

test( 'an unknown site shows an error with Retry, not a broken panel', async () => {
	apiFetch.mockRejectedValue( {
		json: async () => ( {
			code: 'msradar_site_not_found',
			message: 'Site not found.',
			data: { status: 404 },
		} ),
	} );

	setup( { siteId: 999999, preload: {} } );

	expect(
		screen.getByRole( 'heading', { name: 'Site #999999' } )
	).toBeInTheDocument();
	const dialog = screen.getByRole( 'dialog' );
	expect(
		await within( dialog ).findByText( 'Site not found.' )
	).toBeInTheDocument();
	expect(
		within( dialog ).getByRole( 'button', { name: 'Retry' } )
	).toBeInTheDocument();
} );

test( 'users are loaded on demand, page by page', async () => {
	apiFetch.mockImplementation( async ( { path } ) => ( {
		json: async () => [
			{
				id: 1,
				login: 'admin',
				display_name: 'Admin',
				roles: [ 'administrator' ],
				role_names: [ 'Administrateur' ],
				super_admin: true,
				registered_gmt: null,
			},
		],
		headers: new Map( [
			[ 'X-WP-Total', '1' ],
			[ 'X-WP-TotalPages', '1' ],
		] ),
		path,
	} ) );
	setup();

	fireEvent.click( screen.getByRole( 'tab', { name: 'Users' } ) );

	expect( await screen.findByText( 'admin' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Administrateur' ) ).toBeInTheDocument();
	expect( screen.queryByText( 'administrator' ) ).not.toBeInTheDocument();
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/sites/12/users?page=1&per_page=20',
		parse: false,
	} );
	await waitFor( () =>
		expect( screen.getByText( 'Page 1 of 1' ) ).toBeInTheDocument()
	);
} );

function usersResponse( totalPages ) {
	return async () => ( {
		json: async () => [
			{
				id: 1,
				login: 'admin',
				display_name: 'Admin',
				roles: [ 'administrator' ],
				super_admin: false,
				registered_gmt: null,
			},
		],
		headers: new Map( [
			[ 'X-WP-Total', String( totalPages * 20 ) ],
			[ 'X-WP-TotalPages', String( totalPages ) ],
		] ),
	} );
}

const userPaths = () =>
	apiFetch.mock.calls
		.map( ( [ args ] ) => args.path )
		.filter( ( p ) => p.includes( '/users' ) );

test( 'no users request before the Users tab is opened', async () => {
	apiFetch.mockImplementation( usersResponse( 1 ) );
	setup();

	await act( () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ) );

	expect( userPaths() ).toEqual( [] );
} );

test( 'the pager follows X-WP-TotalPages and never requests out of range', async () => {
	apiFetch.mockImplementation( usersResponse( 3 ) );
	setup();
	fireEvent.click( screen.getByRole( 'tab', { name: 'Users' } ) );

	expect( await screen.findByText( 'Page 1 of 3' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Previous' } ) ).toBeDisabled();

	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	expect( await screen.findByText( 'Page 2 of 3' ) ).toBeInTheDocument();
	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	expect( await screen.findByText( 'Page 3 of 3' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeDisabled();

	expect( userPaths() ).toEqual( [
		'/multisite-radar/v1/sites/12/users?page=1&per_page=20',
		'/multisite-radar/v1/sites/12/users?page=2&per_page=20',
		'/multisite-radar/v1/sites/12/users?page=3&per_page=20',
	] );
} );

test( 'changing site restarts the users list at page 1', async () => {
	apiFetch.mockImplementation( usersResponse( 3 ) );
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/sites/12': { body: DETAIL, headers: {} },
			'/multisite-radar/v1/sites/13': {
				body: { ...DETAIL, id: 13, name: 'Gamma' },
				headers: {},
			},
		} )
	);
	const ui = ( siteId ) => (
		<RegistryProvider value={ registry }>
			<SitePanel
				siteId={ siteId }
				items={ ITEMS }
				onNavigate={ vi.fn() }
				onClose={ vi.fn() }
			/>
		</RegistryProvider>
	);
	const { rerender } = render( ui( 12 ) );
	fireEvent.click( screen.getByRole( 'tab', { name: 'Users' } ) );
	await screen.findByText( 'Page 1 of 3' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	await screen.findByText( 'Page 2 of 3' );

	rerender( ui( 13 ) );

	expect( await screen.findByText( 'Page 1 of 3' ) ).toBeInTheDocument();
	const forNext = userPaths().filter( ( p ) => p.includes( '/sites/13/' ) );
	expect( forNext ).toEqual( [
		'/multisite-radar/v1/sites/13/users?page=1&per_page=20',
	] );
} );
