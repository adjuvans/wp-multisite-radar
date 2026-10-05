import { expect, test, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import UserPanel from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

function renderPanel( body ) {
	const preload = {
		'/multisite-radar/v1/users/7': { body, headers: {} },
	};
	window.msradarAdmin = { pages: {}, preload };
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	const panel = ( userId, title ) => (
		<RegistryProvider value={ registry }>
			<UserPanel userId={ userId } title={ title } onClose={ vi.fn() } />
		</RegistryProvider>
	);
	const { rerender } = render( panel( 7, 'jo' ) );
	return {
		showAccount: ( userId, title ) => rerender( panel( userId, title ) ),
	};
}

const ACCOUNT = {
	id: 7,
	login: 'jo',
	display_name: 'Jo Martin',
	first_name: 'Jo',
	last_name: 'Martin',
	email: 'jo@example.test',
	super_admin: false,
	registered_gmt: '2026-01-01T10:00:00',
	edit_url: 'https://example.test/wp-admin/network/user-edit.php?user_id=7',
	published: 5,
	sites: [
		{
			id: 2,
			name: 'Blog RH',
			admin_url: 'https://example.test/rh/wp-admin/',
			roles: [ { role: 'editor', label: 'Editor' } ],
			published: 5,
		},
		{
			id: 3,
			name: 'Atelier',
			admin_url: 'https://example.test/atelier/wp-admin/',
			roles: [ { role: 'subscriber', label: 'Subscriber' } ],
			published: null,
		},
	],
	sites_total: 4,
};

test( 'the account panel shows the identity, the sites with their role and how many more there are', () => {
	renderPanel( ACCOUNT );

	expect(
		screen.getByRole( 'dialog', { name: 'Jo Martin' } )
	).toBeInTheDocument();
	expect( screen.getByText( 'jo@example.test' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Blog RH' } ) ).toHaveAttribute(
		'href',
		'https://example.test/rh/wp-admin/'
	);
	expect( screen.getByText( /Editor/ ) ).toBeInTheDocument();
	expect( screen.getByText( 'And 2 more sites.' ) ).toBeInTheDocument();
	expect(
		screen.getByRole( 'link', { name: 'Edit the account' } )
	).toHaveAttribute( 'href', ACCOUNT.edit_url );
} );

test( 'without the e-mail the panel shows no e-mail row', () => {
	const { email, ...withoutEmail } = ACCOUNT;
	renderPanel( { ...withoutEmail, sites_total: 2 } );

	expect( screen.queryByText( 'Email' ) ).not.toBeInTheDocument();
	expect( screen.queryByText( /more site/ ) ).not.toBeInTheDocument();
	expect( email ).toBe( 'jo@example.test' );
} );

test( 'while the next account loads, the panel shows its title and a skeleton, not the previous account', () => {
	// Le compte 8 n'est pas préchargé : apiFetch, simulé, ne répond jamais.
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	const { showAccount } = renderPanel( ACCOUNT );
	expect( screen.getByText( 'jo@example.test' ) ).toBeInTheDocument();

	showAccount( 8, 'sam' );

	expect( screen.getByRole( 'dialog', { name: 'sam' } ) ).toBeInTheDocument();
	expect( screen.getByText( 'Loading the account…' ) ).toBeInTheDocument();
	expect( screen.queryByText( 'jo@example.test' ) ).not.toBeInTheDocument();
	expect(
		screen.queryByRole( 'link', { name: 'Edit the account' } )
	).not.toBeInTheDocument();
} );
