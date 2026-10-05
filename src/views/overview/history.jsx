import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pageUrl } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import EventsList from '../../components/events-list';
import TrendChart from '../../components/trend-chart';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';

/**
 * Les cinq derniers changements du réseau (écart E10 du plan M6).
 */
export function RecentChanges() {
	const reports = pageUrl( 'reports' );
	return (
		<Card className="msradar-recent-changes">
			<CardHeader>
				<h2>{ __( 'Recent changes', 'multisite-radar' ) }</h2>
				{ reports && (
					<a href={ reports }>
						{ __( 'See all changes', 'multisite-radar' ) }
					</a>
				) }
			</CardHeader>
			<CardBody>
				<EventsList perPage={ 5 } compact />
			</CardBody>
		</Card>
	);
}

/**
 * Sites par gravité de leur alerte la plus haute, sur 30 jours.
 */
export function AlertsTrend() {
	const trends = useResource( buildPath( '/reports/trends', { days: 30 } ) );
	return (
		<Card className="msradar-alerts-trend">
			<CardHeader>
				<h2>{ __( 'Alerts, last 30 days', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ trends.error } onRetry={ trends.retry } />
				{ trends.data && (
					<TrendChart
						title={ __(
							'Sites by highest alert',
							'multisite-radar'
						) }
						points={ trends.data.points }
						series={ [
							{
								key: 'alerts_error',
								label: __( 'Error', 'multisite-radar' ),
								tone: 'error',
							},
							{
								key: 'alerts_warning',
								label: __( 'Warning', 'multisite-radar' ),
								tone: 'warning',
							},
							{
								key: 'alerts_info',
								label: __( 'Information', 'multisite-radar' ),
								tone: 'info',
							},
						] }
					/>
				) }
			</CardBody>
		</Card>
	);
}
