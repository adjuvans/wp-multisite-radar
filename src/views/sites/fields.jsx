import { __ } from '@wordpress/i18n';
import {
	RegistryBadge,
	SeverityBadge,
	severityLabels,
} from '../../components/badges';
import {
	displayUrl,
	formatBytes,
	formatDateTime,
	formatDisk,
	formatNumber,
	formatRelative,
} from '../../utils/format';
import { registryLabels, siteStatuses, statusLabels } from './labels';
import { REGISTRY_STATUSES, STATUSES } from './query';

export function SiteTitle( { item } ) {
	return (
		<span className="msradar-site-title">
			<span className="msradar-site-title__name">{ item.name }</span>
			<span className="msradar-site-title__url">
				{ displayUrl( item.url ) }
			</span>
		</span>
	);
}

export function DateCell( { value } ) {
	if ( ! value ) {
		return <span>—</span>;
	}
	return (
		<time dateTime={ `${ value }Z` } title={ formatDateTime( value ) }>
			{ formatRelative( value ) }
		</time>
	);
}

/**
 * Champs DataViews de la liste des sites. Les identifiants sont les clés REST (et celles de l'export).
 *
 * @param {Array} rules by_rule de /alerts/summary (libellés des règles).
 */
export function getSitesFields( rules = [] ) {
	const statuses = statusLabels();
	const severities = severityLabels();
	const registry = registryLabels();
	const count = ( id, label ) => ( {
		id,
		type: 'integer',
		label,
		filterBy: false,
		render: ( { item } ) => formatNumber( item[ id ] ),
	} );
	const ruleLabel = ( id ) =>
		rules.find( ( rule ) => rule.rule === id )?.label || id;

	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Site', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => <SiteTitle item={ item } />,
		},
		{
			id: 'theme',
			type: 'text',
			label: __( 'Theme', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.theme?.stylesheet || '',
		},
		count( 'users_count', __( 'Users', 'multisite-radar' ) ),
		count( 'content_count', __( 'Published content', 'multisite-radar' ) ),
		count( 'media_count', __( 'Media', 'multisite-radar' ) ),
		{
			id: 'disk_bytes',
			type: 'integer',
			label: __( 'Disk', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) =>
				formatDisk( item.disk_bytes, item.disk_is_estimate ),
		},
		{
			id: 'db_bytes',
			type: 'integer',
			label: __( 'Database', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => formatBytes( item.db_bytes ),
		},
		{
			id: 'last_activity_gmt',
			type: 'datetime',
			label: __( 'Last activity', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => (
				<DateCell value={ item.last_activity_gmt } />
			),
		},
		{
			id: 'alert_level',
			type: 'text',
			label: __( 'Alerts', 'multisite-radar' ),
			elements: [ 'error', 'warning', 'info', 'none' ].map(
				( value ) => ( {
					value,
					label: severities[ value ],
				} )
			),
			filterBy: { operators: [ 'isAny' ] },
			render: ( { item } ) => (
				<SeverityBadge
					level={ item.alert_level }
					count={ item.alerts_count }
				/>
			),
		},
		{
			id: 'rule',
			type: 'text',
			label: __( 'Alert rule', 'multisite-radar' ),
			elements: rules.map( ( rule ) => ( {
				value: rule.rule,
				label: rule.label,
			} ) ),
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
			getValue: ( { item } ) => ( item.alert_rules || [] ).join( ',' ),
			render: ( { item } ) =>
				( item.alert_rules || [] ).map( ruleLabel ).join( ', ' ),
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'multisite-radar' ),
			elements: STATUSES.map( ( value ) => ( {
				value,
				label: statuses[ value ],
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) => siteStatuses( item ).join( ',' ),
			render: ( { item } ) =>
				siteStatuses( item )
					.map( ( value ) => statuses[ value ] )
					.join( ', ' ),
		},
		{
			id: 'registry_status',
			type: 'text',
			label: __( 'Content types', 'multisite-radar' ),
			elements: REGISTRY_STATUSES.map( ( value ) => ( {
				value,
				label: registry[ value ],
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			render: ( { item } ) =>
				item.registry_status === 'fresh' ? (
					registry.fresh
				) : (
					<RegistryBadge status={ item.registry_status } />
				),
		},
		{
			id: 'scanned_at_gmt',
			type: 'datetime',
			label: __( 'Analysed', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) =>
				item.pending ? (
					<span className="msradar-pending">
						{ __( 'Pending', 'multisite-radar' ) }
					</span>
				) : (
					<DateCell value={ item.scanned_at_gmt } />
				),
		},
	];
}
