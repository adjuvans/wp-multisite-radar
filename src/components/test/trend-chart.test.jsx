import { expect, test } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import TrendChart from '../trend-chart';

const POINTS = [
	{ day: '2026-09-01', content_count: 10, media_count: 4 },
	{ day: '2026-09-02', content_count: 12, media_count: 5 },
	{ day: '2026-09-04', content_count: 15, media_count: null },
];
const SERIES = [
	{ key: 'content_count', label: 'Content' },
	{ key: 'media_count', label: 'Media', tone: 'info' },
];

test( 'with fewer than two points, a message replaces the chart', () => {
	render(
		<TrendChart
			title="Content"
			points={ POINTS.slice( 0, 1 ) }
			series={ SERIES }
		/>
	);

	expect( screen.getByText( /Not enough history yet/ ) ).toBeInTheDocument();
	expect( screen.queryByRole( 'img' ) ).toBeNull();
} );

test( 'a line is broken where a day or a value is missing', () => {
	const { container } = render(
		<TrendChart title="Content" points={ POINTS } series={ SERIES } />
	);

	// Contenus : le 1er et le 2 reliés, le 4 isolé après un jour manquant (un point) ; médias : le 4 n'a pas de valeur.
	expect(
		container.querySelectorAll( 'polyline.msradar-trend__line--accent' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'circle.msradar-trend__dot--accent' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'polyline.msradar-trend__line--info' )
	).toHaveLength( 1 );
	expect(
		container.querySelectorAll( 'circle.msradar-trend__dot--info' )
	).toHaveLength( 0 );
} );

test( 'the chart has a text alternative and a table of the values', () => {
	render(
		<TrendChart title="Content" points={ POINTS } series={ SERIES } />
	);

	expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
		/^Content, from .+ to .+\. Latest: Content: 15, Media: —\.$/
	);
	const table = screen.getByRole( 'table', { hidden: true } );
	expect(
		within( table ).getAllByRole( 'row', { hidden: true } )
	).toHaveLength( 4 );
} );

test( 'a series can format its values', () => {
	render(
		<TrendChart
			title="Disk"
			points={ POINTS }
			series={ [
				{
					key: 'content_count',
					label: 'Disk',
					format: ( value ) => `${ value } B`,
				},
			] }
		/>
	);

	expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
		/Latest: Disk: 15 B\.$/
	);
} );
