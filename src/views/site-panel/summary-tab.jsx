import { ExternalLink, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { displayUrl, formatDateTime, formatNumber } from '../../utils/format';
import { siteStatuses, statusLabels } from '../sites/labels';

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
						__(
							'%1$s, including %2$s administrators',
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
