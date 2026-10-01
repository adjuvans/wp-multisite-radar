import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
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
