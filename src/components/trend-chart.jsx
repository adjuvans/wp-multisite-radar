import { __, sprintf } from '@wordpress/i18n';
import { formatDay, formatNumber } from '../utils/format';

const WIDTH = 600;
const HEIGHT = 160;
const PAD = 8;
const DAY_MS = 86400000;

function time( day ) {
	return Date.parse( `${ day }T00:00:00Z` );
}

/**
 * Morceaux d'une série : des points de jours consécutifs ayant une valeur. Un jour manquant ou une valeur nulle
 * coupe la ligne (écart E9 du plan M6).
 *
 * @param {Array}  points Points triés par jour.
 * @param {string} key    Clé de la série.
 * @return {Array<Array<Object>>} Morceaux.
 */
function segments( points, key ) {
	const parts = [];
	let current = [];
	let previous = null;
	points.forEach( ( point ) => {
		const value = point[ key ];
		if ( value === null || value === undefined ) {
			if ( current.length ) {
				parts.push( current );
			}
			current = [];
			previous = null;
			return;
		}
		const t = time( point.day );
		if ( previous !== null && t - previous > DAY_MS ) {
			parts.push( current );
			current = [];
		}
		current.push( { t, value } );
		previous = t;
	} );
	if ( current.length ) {
		parts.push( current );
	}
	return parts;
}

/**
 * Courbes SVG internes (spec §6.3) : une ligne par série ; l'axe x suit les dates, l'axe y va de 0 au maximum.
 * Un résumé textuel (aria-label) et le tableau des valeurs en sont l'équivalent accessible.
 *
 * @param {Object} props
 * @param {string} props.title  Titre.
 * @param {Array}  props.points Points { day, …valeurs }, triés par jour.
 * @param {Array}  props.series Séries { key, label, tone, format }.
 */
export default function TrendChart( { title, points, series } ) {
	if ( points.length < 2 ) {
		return (
			<figure className="msradar-trend">
				<figcaption className="msradar-trend__title">
					{ title }
				</figcaption>
				<p className="msradar-trend__empty">
					{ __(
						'Not enough history yet: the chart appears after two daily snapshots.',
						'multisite-radar'
					) }
				</p>
			</figure>
		);
	}

	const first = time( points[ 0 ].day );
	const last = time( points[ points.length - 1 ].day );
	const max = Math.max(
		1,
		...points.flatMap( ( point ) =>
			series.map( ( item ) => point[ item.key ] || 0 )
		)
	);
	const x = ( t ) =>
		PAD +
		( ( t - first ) / Math.max( 1, last - first ) ) * ( WIDTH - 2 * PAD );
	const y = ( value ) =>
		HEIGHT - PAD - ( value / max ) * ( HEIGHT - 2 * PAD );
	const latest = points[ points.length - 1 ];
	const format = ( item, value ) =>
		value === null || value === undefined
			? formatNumber( value )
			: ( item.format || formatNumber )( value );
	const values = series
		.map( ( item ) =>
			sprintf(
				/* translators: 1: series name, 2: value. */
				__( '%1$s: %2$s', 'multisite-radar' ),
				item.label,
				format( item, latest[ item.key ] )
			)
		)
		.join( ', ' );
	const label = sprintf(
		/* translators: 1: chart title, 2: first day, 3: last day, 4: latest values. */
		__( '%1$s, from %2$s to %3$s. Latest: %4$s.', 'multisite-radar' ),
		title,
		formatDay( points[ 0 ].day ),
		formatDay( latest.day ),
		values
	);

	return (
		<figure className="msradar-trend">
			<figcaption className="msradar-trend__title">{ title }</figcaption>
			<div className="msradar-trend__plot">
				<span className="msradar-trend__max" aria-hidden="true">
					{ format( series[ 0 ], max ) }
				</span>
				<svg
					className="msradar-trend__chart"
					viewBox={ `0 0 ${ WIDTH } ${ HEIGHT }` }
					preserveAspectRatio="none"
					role="img"
					aria-label={ label }
				>
					{ series.map( ( item ) => {
						const tone = item.tone || 'accent';
						return segments( points, item.key ).map(
							( part, index ) =>
								part.length === 1 ? (
									<circle
										key={ `${ item.key }-${ index }` }
										className={ `msradar-trend__dot msradar-trend__dot--${ tone }` }
										cx={ x( part[ 0 ].t ) }
										cy={ y( part[ 0 ].value ) }
										r="3"
									/>
								) : (
									<polyline
										key={ `${ item.key }-${ index }` }
										className={ `msradar-trend__line msradar-trend__line--${ tone }` }
										fill="none"
										vectorEffect="non-scaling-stroke"
										points={ part
											.map(
												( p ) =>
													`${ x( p.t ) },${ y( p.value ) }`
											)
											.join( ' ' ) }
									/>
								)
						);
					} ) }
				</svg>
				<div className="msradar-trend__axis" aria-hidden="true">
					<span>{ formatDay( points[ 0 ].day ) }</span>
					<span>{ formatDay( latest.day ) }</span>
				</div>
			</div>
			<ul className="msradar-trend__legend">
				{ series.map( ( item ) => (
					<li key={ item.key }>
						<span
							className={ `msradar-trend__swatch msradar-trend__swatch--${
								item.tone || 'accent'
							}` }
							aria-hidden="true"
						/>
						{ item.label }{ ' ' }
						<strong>{ format( item, latest[ item.key ] ) }</strong>
					</li>
				) ) }
			</ul>
			<details className="msradar-trend__data">
				<summary>{ __( 'Show the data', 'multisite-radar' ) }</summary>
				<table className="widefat striped">
					<caption className="screen-reader-text">{ title }</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Day', 'multisite-radar' ) }
							</th>
							{ series.map( ( item ) => (
								<th scope="col" key={ item.key }>
									{ item.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ points.map( ( point ) => (
							<tr key={ point.day }>
								<th scope="row">{ formatDay( point.day ) }</th>
								{ series.map( ( item ) => (
									<td key={ item.key }>
										{ format( item, point[ item.key ] ) }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</details>
		</figure>
	);
}
