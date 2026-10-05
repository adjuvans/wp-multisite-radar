import { SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import Skeleton from '../../components/skeleton';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import { formatBytes } from '../../utils/format';

const PERIODS = [ 30, 90, 365 ];

/**
 * Onglet Historique de la fiche (spec §6.2) : tendances du site et ses derniers changements, chargés à la demande.
 *
 * @param {Object} props
 * @param {number} props.siteId ID du site.
 */
export default function HistoryTab( { siteId } ) {
	const [ days, setDays ] = useState( 90 );
	const trends = useResource(
		buildPath( '/reports/trends', { days, site: siteId } )
	);
	const points = trends.data?.points || [];

	return (
		<div className="msradar-history">
			<SelectControl
				id="msradar-site-history-period"
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Period', 'multisite-radar' ) }
				value={ String( days ) }
				options={ PERIODS.map( ( value ) => ( {
					value: String( value ),
					label: sprintf(
						/* translators: %d: number of days. */
						_n(
							'Last %d day',
							'Last %d days',
							value,
							'multisite-radar'
						),
						value
					),
				} ) ) }
				onChange={ ( value ) => setDays( Number( value ) ) }
			/>
			<ErrorNotice error={ trends.error } onRetry={ trends.retry } />
			{ ! trends.data && ! trends.error && (
				<Skeleton
					lines={ 4 }
					label={ __( 'Loading the trends…', 'multisite-radar' ) }
				/>
			) }
			{ trends.data && (
				<>
					<TrendChart
						title={ __( 'Content and media', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'content_count',
								label: __( 'Content', 'multisite-radar' ),
							},
							{
								key: 'media_count',
								label: __( 'Media', 'multisite-radar' ),
								tone: 'info',
							},
						] }
					/>
					<TrendChart
						title={ __( 'Users', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'users_count',
								label: __( 'Users', 'multisite-radar' ),
							},
						] }
					/>
					<TrendChart
						title={ __( 'Disk and database', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'disk_bytes',
								label: __( 'Disk', 'multisite-radar' ),
								format: formatBytes,
							},
							{
								key: 'db_bytes',
								label: __( 'Database', 'multisite-radar' ),
								tone: 'info',
								format: formatBytes,
							},
						] }
					/>
					<TrendChart
						title={ __( 'Alerts', 'multisite-radar' ) }
						points={ points }
						series={ [
							{
								key: 'alerts_count',
								label: __( 'Alerts', 'multisite-radar' ),
								tone: 'warning',
							},
						] }
					/>
				</>
			) }
			<h3>{ __( 'Changes', 'multisite-radar' ) }</h3>
			<EventsList site={ siteId } perPage={ 10 } compact />
		</div>
	);
}
