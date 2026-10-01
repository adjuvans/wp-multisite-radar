import { __ } from '@wordpress/i18n';
import { SeverityBadge, severityLabels } from '../../components/badges';
import { SiteTitle } from '../sites/fields';
import { SEVERITIES } from './query';

export function getAlertsFields( rules = [] ) {
	const severities = severityLabels();
	return [
		{
			id: 'site',
			type: 'text',
			label: __( 'Site', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => item.site.name,
			render: ( { item } ) => <SiteTitle item={ item.site } />,
		},
		{
			id: 'rule',
			type: 'text',
			label: __( 'Rule', 'multisite-radar' ),
			elements: rules.map( ( rule ) => ( {
				value: rule.rule,
				label: rule.label,
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item.rule,
			render: ( { item } ) => item.label,
		},
		{
			id: 'severity',
			type: 'text',
			label: __( 'Severity', 'multisite-radar' ),
			elements: SEVERITIES.map( ( value ) => ( {
				value,
				label: severities[ value ],
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			render: ( { item } ) => <SeverityBadge level={ item.severity } />,
		},
		{
			id: 'message',
			type: 'text',
			label: __( 'Details', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
		},
	];
}
