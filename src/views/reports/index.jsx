import {
	Card,
	CardBody,
	CardHeader,
	SelectControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import Skeleton from '../../components/skeleton';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import DigestCard from './digest-card';

const PERIODS = [ 30, 90, 365 ];

function periods() {
	return PERIODS.map( ( days ) => ( {
		value: String( days ),
		label: sprintf(
			/* translators: %d: number of days. */
			_n( 'Last %d day', 'Last %d days', days, 'multisite-radar' ),
			days
		),
	} ) );
}

/**
 * Page Rapports (spec §6.2) : tendances du réseau, « Quoi de neuf » et réglage du récapitulatif.
 */
export default function ReportsView() {
	const { canManage } = getConfig();
	const [ days, setDays ] = useState( 90 );
	const trends = useResource( buildPath( '/reports/trends', { days } ) );
	const points = trends.data?.points || [];

	return (
		<div className="msradar-reports">
			<Card>
				<CardHeader>
					<h2>{ __( 'Trends', 'multisite-radar' ) }</h2>
					<SelectControl
						id="msradar-reports-period"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Period', 'multisite-radar' ) }
						value={ String( days ) }
						options={ periods() }
						onChange={ ( value ) => setDays( Number( value ) ) }
					/>
				</CardHeader>
				<CardBody>
					<ErrorNotice
						error={ trends.error }
						onRetry={ trends.retry }
					/>
					{ ! trends.data && ! trends.error && (
						<Skeleton
							lines={ 4 }
							label={ __(
								'Loading the trends…',
								'multisite-radar'
							) }
						/>
					) }
					{ trends.data && (
						<div className="msradar-reports__trends">
							<TrendChart
								title={ __( 'Sites', 'multisite-radar' ) }
								points={ points }
								series={ [
									{
										key: 'sites',
										label: __( 'Sites', 'multisite-radar' ),
									},
								] }
							/>
							<TrendChart
								title={ __(
									'Content and media',
									'multisite-radar'
								) }
								points={ points }
								series={ [
									{
										key: 'content_count',
										label: __(
											'Content',
											'multisite-radar'
										),
									},
									{
										key: 'media_count',
										label: __( 'Media', 'multisite-radar' ),
										tone: 'info',
									},
								] }
							/>
							<TrendChart
								title={ __(
									'Sites by highest alert',
									'multisite-radar'
								) }
								points={ points }
								series={ [
									{
										key: 'alerts_error',
										label: __( 'Error', 'multisite-radar' ),
										tone: 'error',
									},
									{
										key: 'alerts_warning',
										label: __(
											'Warning',
											'multisite-radar'
										),
										tone: 'warning',
									},
									{
										key: 'alerts_info',
										label: __(
											'Information',
											'multisite-radar'
										),
										tone: 'info',
									},
								] }
							/>
						</div>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2>{ __( "What's new", 'multisite-radar' ) }</h2>
				</CardHeader>
				<CardBody>
					<EventsList />
				</CardBody>
			</Card>
			{ canManage && <DigestCard /> }
		</div>
	);
}
