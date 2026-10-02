import { ExternalLink, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	displayUrl,
	formatBytes,
	formatDateTime,
	formatDisk,
	formatNumber,
} from '../../utils/format';
import { siteStatuses, statusLabels } from '../sites/labels';

/**
 * Tâches planifiées en retard lors de la dernière analyse.
 *
 * @param {?Object} cron { overdue_count, oldest_overdue_gmt }, null pour une ligne analysée avant la 2.0.0-beta.4.
 */
function cronSummary( cron ) {
	if ( ! cron ) {
		return '—';
	}
	if ( cron.overdue_count === 0 ) {
		return __( 'None overdue', 'multisite-radar' );
	}
	return sprintf(
		/* translators: 1: number of overdue scheduled tasks, 2: date the oldest one was due. */
		_n(
			'%1$s overdue at the last analysis, due since %2$s',
			'%1$s overdue at the last analysis, the oldest due since %2$s',
			cron.overdue_count,
			'multisite-radar'
		),
		formatNumber( cron.overdue_count ),
		formatDateTime( cron.oldest_overdue_gmt )
	);
}

export default function SummaryTab( { site } ) {
	const statuses = statusLabels();
	const theme = site.extensions?.theme;
	return (
		<>
			{ site.scan_error && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: date of the failed analysis, 2: error message. */
						__(
							'The last analysis failed on %1$s: %2$s',
							'multisite-radar'
						),
						formatDateTime( site.scan_error.at_gmt ),
						site.scan_error.message
					) }
				</Notice>
			) }
			{ site.registry_status !== 'fresh' && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The content types of this site have not yet been read in its own context, so their labels and origins are not verified. Run "wp multisite-radar scan --probe" to read them now.',
						'multisite-radar'
					) }
				</Notice>
			) }
			<dl className="msradar-facts">
				<dt>{ __( 'Address', 'multisite-radar' ) }</dt>
				<dd>
					<ExternalLink href={ site.url }>
						{ displayUrl( site.url ) }
					</ExternalLink>
				</dd>
				<dt>{ __( 'Administration', 'multisite-radar' ) }</dt>
				<dd>
					<a href={ site.admin_url }>
						{ __( 'Site dashboard', 'multisite-radar' ) }
					</a>
				</dd>
				<dt>{ __( 'Status', 'multisite-radar' ) }</dt>
				<dd>
					{ siteStatuses( site )
						.map( ( key ) => statuses[ key ] )
						.join( ', ' ) }
				</dd>
				<dt>{ __( 'Theme', 'multisite-radar' ) }</dt>
				<dd>{ theme?.name || site.theme?.stylesheet || '—' }</dd>
				<dt>{ __( 'Users', 'multisite-radar' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: 1: number of users, 2: number of administrators. */
						_n(
							'%1$s, including %2$s administrator',
							'%1$s, including %2$s administrators',
							site.admins_count,
							'multisite-radar'
						),
						formatNumber( site.users_count ),
						formatNumber( site.admins_count )
					) }
				</dd>
				<dt>{ __( 'Published content', 'multisite-radar' ) }</dt>
				<dd>{ formatNumber( site.content_count ) }</dd>
				<dt>{ __( 'Media', 'multisite-radar' ) }</dt>
				<dd>{ formatNumber( site.media_count ) }</dd>
				<dt>{ __( 'Disk', 'multisite-radar' ) }</dt>
				<dd>
					{ formatDisk( site.disk_bytes, site.disk_is_estimate ) }
				</dd>
				<dt>{ __( 'Database', 'multisite-radar' ) }</dt>
				<dd>{ formatBytes( site.db_bytes ) }</dd>
				<dt>{ __( 'Autoloaded options', 'multisite-radar' ) }</dt>
				<dd>{ formatBytes( site.autoload_bytes ) }</dd>
				<dt>{ __( 'Scheduled tasks', 'multisite-radar' ) }</dt>
				<dd>{ cronSummary( site.cron ) }</dd>
				<dt>{ __( 'Last activity', 'multisite-radar' ) }</dt>
				<dd>
					{ formatDateTime( site.last_activity_gmt ) }
					{ site.last_content?.title
						? ` — ${ site.last_content.title }`
						: '' }
				</dd>
				<dt>{ __( 'Analysed', 'multisite-radar' ) }</dt>
				<dd>
					{ site.pending
						? __( 'Pending', 'multisite-radar' )
						: formatDateTime( site.scanned_at_gmt ) }
				</dd>
			</dl>
		</>
	);
}
