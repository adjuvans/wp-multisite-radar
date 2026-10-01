import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getConfig } from '../../admin/config';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useScan } from '../../hooks/use-scan';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { samePrefs } from '../../utils/view-query';
import { getSitesActions } from './actions';
import { exportUrl } from './export';
import ExportMenu from './export-menu';
import { getSitesFields } from './fields';
import {
	fromSitesView,
	parseSitesQuery,
	serializeSitesState,
	sitesPath,
	sitesPrefsFromView,
	toSitesView,
} from './query';

const DEFAULT_LAYOUTS = {
	table: {},
	grid: { layout: { badgeFields: [ 'alert_level' ] } },
};

export default function SitesView() {
	const { canManage } = getConfig();
	const [ state, setState ] = useUrlState(
		parseSitesQuery,
		serializeSitesState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'sites' );
	// Les préférences changées s'appliquent tout de suite ; l'enregistrement suit en arrière-plan.
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const sitesPrefs = localPrefs || prefs?.sites || null;

	const summary = useResource( buildPath( '/alerts/summary' ) );
	const list = useResource(
		sitesPrefs ? sitesPath( state, { sites: sitesPrefs } ) : null
	);
	const scan = useScan();
	const startScan = scan.start;

	const rules = summary.data?.by_rule;
	const fields = useMemo( () => getSitesFields( rules || [] ), [ rules ] );
	const view = useMemo(
		() => toSitesView( state, sitesPrefs ),
		[ state, sitesPrefs ]
	);

	const openSite = useCallback(
		( item ) =>
			setState( ( current ) => ( { ...current, site: item.id } ) ),
		[ setState ]
	);
	const actions = useMemo(
		() =>
			getSitesActions( {
				canManage,
				onOpen: openSite,
				onRescan: ( ids ) => startScan( { scope: 'ids', ids } ),
				onExport: ( ids ) =>
					window.location.assign(
						exportUrl( 'csv', state, view.fields, ids )
					),
			} ),
		[ canManage, openSite, startScan, state, view.fields ]
	);

	const onChangeView = ( next ) => {
		setState( ( current ) => fromSitesView( next, current ) );
		const nextPrefs = sitesPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, sitesPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-sites">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				defaultLayouts={ DEFAULT_LAYOUTS }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ list.isLoading && ! list.data }
				getItemId={ ( item ) => String( item.id ) }
				isItemClickable={ () => true }
				onClickItem={ openSite }
				searchLabel={ __( 'Search sites', 'multisite-radar' ) }
				header={ <ExportMenu state={ state } fields={ view.fields } /> }
				empty={
					<p className="msradar-empty">
						{ __(
							'No site matches this view.',
							'multisite-radar'
						) }
					</p>
				}
			/>
			{ scan.running && (
				<p className="msradar-inline-status" role="status">
					{ sprintf(
						/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
						__(
							'Analysing: %1$d of %2$d sites…',
							'multisite-radar'
						),
						scan.processed,
						scan.total
					) }
				</p>
			) }
		</div>
	);
}
