import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from '@wordpress/element';
import Skeleton from '../skeleton';
import SidePanel from '../side-panel';
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
