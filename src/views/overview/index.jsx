import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { useScan } from '../../hooks/use-scan';
import { buildPath } from '../../store/paths';
import { ScanPanel, ScanProgress } from './scan-panel';
import { Tiles, ToReview } from './tiles';

function FirstRun( { total, scan, canManage } ) {
	return (
		<Notice
			status="info"
			isDismissible={ false }
			className="msradar-first-run"
		>
			<p>
				{ sprintf(
					/* translators: %d: number of sites in the network. */
					_n(
						'Multisite Radar has not analysed your %d site yet. The analysis runs in the background; you can also start it now.',
						'Multisite Radar has not analysed your %d sites yet. The analysis runs in the background; you can also start it now.',
						total,
						'multisite-radar'
					),
					total
				) }
			</p>
			{ scan.running && <ScanProgress scan={ scan } /> }
			{ canManage && ! scan.running && (
				<Button
					variant="primary"
					onClick={ () => scan.start( { scope: 'all' } ) }
				>
					{ __( 'Start the analysis', 'multisite-radar' ) }
				</Button>
			) }
		</Notice>
	);
}

export default function OverviewView() {
	const { canManage } = getConfig();
	const summary = useResource( buildPath( '/alerts/summary' ) );
	const status = useResource( buildPath( '/scan/status' ) );
	const scan = useScan();
	const data = summary.data;
	const firstRun =
		!! data && data.total_sites > 0 && data.scanned_sites === 0;

	return (
		<div className="msradar-overview">
			<ErrorNotice error={ summary.error } onRetry={ summary.retry } />
			<ErrorNotice error={ status.error } onRetry={ status.retry } />
			{ firstRun && (
				<FirstRun
					total={ data.total_sites }
					scan={ scan }
					canManage={ canManage }
				/>
			) }
			{ ! data && ! summary.error && (
				<Skeleton
					lines={ 2 }
					label={ __( 'Loading the overview…', 'multisite-radar' ) }
				/>
			) }
			{ data && <Tiles summary={ data } /> }
			<div className="msradar-overview__columns">
				{ data && <ToReview rules={ data.by_rule } /> }
				<ScanPanel
					status={ status.data }
					scan={ scan }
					canManage={ canManage }
				/>
			</div>
		</div>
	);
}
