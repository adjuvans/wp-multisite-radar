import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, formatRelative } from '../../utils/format';

export function ScanProgress( { scan } ) {
	return (
		<div className="msradar-progress">
			<progress
				max={ Math.max( 1, scan.total ) }
				value={ scan.processed }
				aria-label={ __( 'Analysis progress', 'multisite-radar' ) }
			/>
			<span>
				{ sprintf(
					/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
					__( '%1$d of %2$d sites analysed', 'multisite-radar' ),
					scan.processed,
					scan.total
				) }
			</span>
		</div>
	);
}

export function ScanPanel( { status, scan, canManage } ) {
	return (
		<Card className="msradar-scan">
			<CardHeader>
				<h2>{ __( 'Analysis', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<dl className="msradar-facts">
					<dt>{ __( 'Last full analysis', 'multisite-radar' ) }</dt>
					<dd>
						{ status?.last_full_scan_gmt
							? formatRelative( status.last_full_scan_gmt )
							: __( 'Never', 'multisite-radar' ) }
					</dd>
					<dt>{ __( 'Sites to refresh', 'multisite-radar' ) }</dt>
					<dd>{ formatNumber( status?.remaining ?? 0 ) }</dd>
					<dt>{ __( 'Next background run', 'multisite-radar' ) }</dt>
					<dd>
						{ status?.next_run_gmt
							? formatRelative( status.next_run_gmt )
							: __( 'Not scheduled', 'multisite-radar' ) }
					</dd>
				</dl>
				{ scan.running && <ScanProgress scan={ scan } /> }
				{ canManage && (
					<Button
						variant="primary"
						isBusy={ scan.running }
						disabled={ scan.running }
						onClick={ () => scan.start( { scope: 'all' } ) }
					>
						{ __( 'Analyse all sites', 'multisite-radar' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
