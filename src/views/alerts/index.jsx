import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { info, wordpress } from '@wordpress/icons';
import { pageUrl } from '../../admin/config';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { samePrefs } from '../../utils/view-query';
import { getAlertsFields } from './fields';
import {
	alertsPath,
	alertsPrefsFromView,
	fromAlertsView,
	parseAlertsQuery,
	serializeAlertsState,
	toAlertsView,
} from './query';

function actions() {
	return [
		{
			id: 'open',
			label: __( 'View the site', 'multisite-radar' ),
			icon: info,
			isPrimary: true,
			callback: ( [ item ] ) =>
				window.location.assign(
					pageUrl( 'sites', { site: item.site.id } )
				),
		},
		{
			id: 'admin',
			label: __( 'Site dashboard', 'multisite-radar' ),
			icon: wordpress,
			isEligible: ( item ) => !! item.site.admin_url,
			callback: ( [ item ] ) =>
				window.location.assign( item.site.admin_url ),
		},
	];
}

export default function AlertsView() {
	const [ state, setState ] = useUrlState(
		parseAlertsQuery,
		serializeAlertsState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'alerts' );
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const alertsPrefs = localPrefs || prefs?.alerts || null;

	const summary = useResource( buildPath( '/alerts/summary' ) );
	const list = useResource(
		alertsPrefs ? alertsPath( state, { alerts: alertsPrefs } ) : null
	);
	const rules = summary.data?.by_rule;
	const fields = useMemo( () => getAlertsFields( rules || [] ), [ rules ] );
	const view = useMemo(
		() => toAlertsView( state, alertsPrefs ),
		[ state, alertsPrefs ]
	);
	const rowActions = useMemo( () => actions(), [] );

	const onChangeView = ( next ) => {
		setState( ( current ) => fromAlertsView( next, current ) );
		const nextPrefs = alertsPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, alertsPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-alerts">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ rowActions }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ false }
				getItemId={ ( item ) => item.id }
				searchLabel={ __( 'Search sites', 'multisite-radar' ) }
				empty={
					list.isLoading ? (
						<Skeleton
							lines={ 5 }
							label={ __( 'Loading alerts…', 'multisite-radar' ) }
						/>
					) : (
						<p className="msradar-empty">
							{ __(
								'No alert matches this view.',
								'multisite-radar'
							) }
						</p>
					)
				}
			/>
		</div>
	);
}
