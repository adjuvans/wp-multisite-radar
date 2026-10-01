import { __ } from '@wordpress/i18n';
import { SeverityBadge } from '../../components/badges';

export default function AlertsTab( { site } ) {
	if ( ! site.alerts || site.alerts.length === 0 ) {
		return <p>{ __( 'No alert for this site.', 'multisite-radar' ) }</p>;
	}
	return (
		<ul className="msradar-alert-list">
			{ site.alerts.map( ( alert ) => (
				<li key={ alert.rule }>
					<SeverityBadge level={ alert.severity } />{ ' ' }
					<strong>{ alert.label }</strong> — { alert.message }
				</li>
			) ) }
		</ul>
	);
}
