import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from '@wordpress/element';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { createCoreStore } from '../../store';
import ExtensionSitesPanel from '../extension-sites-panel';
import ExportMenu from '../export-menu';
import Skeleton from '../skeleton';
import SidePanel from '../side-panel';
import Pager from '../pager';
import ErrorNotice from '../error-notice';
import { RegistryBadge, SeverityBadge } from '../badges';

test( 'ErrorNotice shows the message and a Retry button', () => {
	const onRetry = vi.fn();
	const { container } = render(
		<ErrorNotice
			error={ { message: 'Multisite Radar could not read its data.' } }
			onRetry={ onRetry }
		/>
	);

	expect(
		within( container ).getByText(
			'Multisite Radar could not read its data.'
		)
	).toBeInTheDocument();
	fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
	expect( onRetry ).toHaveBeenCalledTimes( 1 );
} );

test( 'ErrorNotice renders nothing without an error', () => {
	const { container } = render( <ErrorNotice error={ null } /> );
	expect( container ).toBeEmptyDOMElement();
} );

test( 'badges', () => {
	render(
		<>
			<SeverityBadge level="error" count={ 2 } />
			<SeverityBadge level="none" count={ 0 } />
			<RegistryBadge status="stale" />
			<RegistryBadge status="fresh" />
		</>
	);

	expect( screen.getByText( 'Error · 2 alerts' ) ).toHaveClass(
		'msradar-badge--error'
	);
	expect( screen.getByText( 'No alert' ) ).toHaveClass(
		'msradar-badge--none'
	);
	expect( screen.getAllByText( 'Not verified' ) ).toHaveLength( 1 );
} );

test( 'Skeleton announces the loading state and draws lines', () => {
	const { container } = render(
		<Skeleton lines={ 4 } label="Loading plugins…" />
	);

	expect( screen.getByRole( 'status' ) ).toHaveAttribute(
		'aria-busy',
		'true'
	);
	expect( screen.getByText( 'Loading plugins…' ) ).toHaveClass(
		'screen-reader-text'
	);
	expect(
		container.querySelectorAll( '.msradar-skeleton__line' )
	).toHaveLength( 4 );
} );

function PanelHarness( { onClose } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<button onClick={ () => setOpen( true ) }>Open</button>
			{ open && (
				<SidePanel
					title="Akismet"
					focusKey="akismet"
					onClose={ () => {
						onClose();
						setOpen( false );
					} }
				>
					<p>Body</p>
				</SidePanel>
			) }
		</>
	);
}

test( 'SidePanel moves the focus to its title, closes on Escape and gives the focus back', () => {
	const onClose = vi.fn();
	render( <PanelHarness onClose={ onClose } /> );
	const opener = screen.getByRole( 'button', { name: 'Open' } );
	opener.focus();

	fireEvent.click( opener );

	const dialog = screen.getByRole( 'dialog', { name: 'Akismet' } );
	expect(
		within( dialog ).getByRole( 'heading', { name: 'Akismet' } )
	).toHaveFocus();
	fireEvent.keyDown( dialog, { key: 'Escape' } );
	expect( onClose ).toHaveBeenCalledTimes( 1 );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	expect( opener ).toHaveFocus();
} );

function renderSitesPanel( body, total ) {
	const path = ( args ) =>
		`/multisite-radar/v1/plugins/akismet/akismet/sites?page=${ args.page }&per_page=${ args.per_page }`;
	const preload = {
		[ path( { page: 1, per_page: 20 } ) ]: {
			body,
			headers: {
				'X-WP-Total': String( total ),
				'X-WP-TotalPages': String( Math.ceil( total / 20 ) || 1 ),
			},
		},
	};
	window.msradarAdmin = {
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
	};
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	render(
		<RegistryProvider value={ registry }>
			<ExtensionSitesPanel
				title="Akismet"
				path={ path }
				note="Network activated."
				describe={ ( site ) =>
					site.id === 3 ? 'Parent of the active theme' : null
				}
				onClose={ vi.fn() }
			/>
		</RegistryProvider>
	);
}

test( 'ExtensionSitesPanel lists the sites with a link to their panel', () => {
	renderSitesPanel(
		[
			{ id: 2, name: 'Blog RH', url: 'https://example.test/rh/' },
			{ id: 3, name: 'Atelier', url: 'https://example.test/atelier/' },
		],
		2
	);

	const dialog = screen.getByRole( 'dialog', { name: 'Akismet' } );
	expect(
		within( dialog ).getByText( 'Network activated.' )
	).toBeInTheDocument();
	expect( within( dialog ).getByText( '2 sites' ) ).toBeInTheDocument();
	expect(
		within( dialog ).getByRole( 'link', { name: 'Blog RH' } )
	).toHaveAttribute(
		'href',
		'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites&site=2'
	);
	expect(
		within( dialog ).getByText( 'Parent of the active theme' )
	).toBeInTheDocument();
	expect(
		within( dialog ).queryByRole( 'button', { name: 'Next' } )
	).not.toBeInTheDocument();
} );

test( 'ExtensionSitesPanel says when no analysed site uses the item', () => {
	renderSitesPanel( [], 0 );

	expect(
		screen.getByText( 'No analysed site uses it.' )
	).toBeInTheDocument();
} );

test( 'ExportMenu offers CSV and JSON', () => {
	// Une adresse en fragment : jsdom suit la navigation sans quitter le document.
	const href = vi.fn( ( format ) => `#export-${ format }` );
	render( <ExportMenu href={ href } /> );

	fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );
	fireEvent.click(
		screen.getByRole( 'menuitem', { name: 'Export as JSON' } )
	);

	expect( href ).toHaveBeenCalledWith( 'json' );
	expect( window.location.hash ).toBe( '#export-json' );
} );

test( 'ExportMenu shows its label next to the icon', () => {
	render( <ExportMenu href={ () => '#' } /> );
	expect(
		screen.getByRole( 'button', { name: 'Export' } )
	).toHaveTextContent( 'Export' );
} );

test( 'Pager keeps a disabled Next focusable and inert', () => {
	const onChange = vi.fn();
	render( <Pager page={ 2 } pages={ 2 } onChange={ onChange } /> );

	const next = screen.getByRole( 'button', { name: 'Next' } );
	expect( next ).toHaveAttribute( 'aria-disabled', 'true' );
	fireEvent.click( next );
	expect( onChange ).not.toHaveBeenCalled();
} );
