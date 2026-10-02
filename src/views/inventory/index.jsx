import { Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { listView } from '@wordpress/icons';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import ExportMenu from '../../components/export-menu';
import ExtensionSitesPanel from '../../components/extension-sites-panel';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { buildPath } from '../../store/paths';
import { columnsFor, exportLink } from '../../utils/export';
import { samePrefs } from '../../utils/view-query';
import {
	extensionSitesPath,
	fromInventoryView,
	INVENTORY_STATUSES,
	inventoryExportArgs,
	inventoryPath,
	inventoryPrefsFromView,
	parseInventoryQuery,
	serializeInventoryState,
	toInventoryView,
} from './query';

/**
 * Page d'inventaire, commune aux plugins et aux thèmes : liste DataViews dont l'état vit dans l'adresse, préférences
 * de l'utilisateur, export, et panneau des sites qui utilisent l'élément choisi.
 *
 * @param {Object}   props
 * @param {string}   props.resource      plugins ou themes : route REST, préférences, export.
 * @param {Array}    props.fields        Champs DataViews (stables d'un rendu à l'autre).
 * @param {string[]} props.defaultFields Champs visibles par défaut.
 * @param {Object}   props.exportColumns { fixed: string[], byField: Object<string, string[]> }.
 * @param {Object}   props.labels        { search, empty, loading }.
 * @param {Function} props.panelNote     ( item ) => texte au-dessus de la liste des sites, ou null.
 * @param {Function} props.describeSite  ( item, site ) => précision sur un site, ou null.
 */
export default function InventoryView( {
	resource,
	fields,
	defaultFields,
	exportColumns,
	labels,
	panelNote = () => null,
	describeSite = () => null,
} ) {
	const statuses = INVENTORY_STATUSES[ resource ];
	const [ state, setState ] = useUrlState(
		( query ) => parseInventoryQuery( query, statuses ),
		serializeInventoryState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, resource );
	// Les préférences changées s'appliquent tout de suite ; l'enregistrement suit en arrière-plan.
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const viewPrefs = localPrefs || prefs?.[ resource ] || null;
	const [ open, setOpen ] = useState( null );

	const summary = useResource( buildPath( '/inventory/summary' ) );
	const list = useResource(
		viewPrefs ? inventoryPath( resource, state, viewPrefs ) : null
	);
	const view = useMemo(
		() => toInventoryView( state, viewPrefs, defaultFields ),
		[ state, viewPrefs, defaultFields ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'sites',
				label: __( 'Show the sites', 'multisite-radar' ),
				icon: listView,
				isPrimary: true,
				callback: ( [ item ] ) => setOpen( item ),
			},
		],
		[]
	);
	const pending = summary.data?.pending_sites || 0;

	const onChangeView = ( next ) => {
		setState( ( current ) => fromInventoryView( next, current, statuses ) );
		const nextPrefs = inventoryPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, viewPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className={ `msradar-inventory msradar-${ resource }` }>
			{ pending > 0 && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="msradar-inventory__pending"
				>
					{ sprintf(
						/* translators: %d: number of sites not analysed yet. */
						_n(
							'%d site has not been analysed yet: what it uses is not counted below.',
							'%d sites have not been analysed yet: what they use is not counted below.',
							pending,
							'multisite-radar'
						),
						pending
					) }
				</Notice>
			) }
			{ summary.data?.networks > 1 && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="msradar-inventory__networks"
				>
					{ __(
						'This installation has several networks. Plugin and theme files are shared by all of them: what is unused on this network may be used on another one.',
						'multisite-radar'
					) }
				</Notice>
			) }
			<ErrorNotice error={ summary.error } onRetry={ summary.retry } />
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ false }
				getItemId={ ( item ) => item.id }
				isItemClickable={ () => true }
				onClickItem={ setOpen }
				searchLabel={ labels.search }
				header={
					<ExportMenu
						href={ ( format ) =>
							exportLink(
								resource,
								format,
								inventoryExportArgs(
									state,
									columnsFor(
										exportColumns.fixed,
										exportColumns.byField,
										view.fields
									)
								)
							)
						}
					/>
				}
				empty={
					list.isLoading ? (
						<Skeleton lines={ 5 } label={ labels.loading } />
					) : (
						<p className="msradar-empty">{ labels.empty }</p>
					)
				}
			/>
			{ open && (
				<ExtensionSitesPanel
					key={ open.id }
					title={ open.name }
					path={ ( args ) =>
						extensionSitesPath( resource, open.id, args )
					}
					note={ panelNote( open ) }
					describe={ ( site ) => describeSite( open, site ) }
					onClose={ () => setOpen( null ) }
				/>
			) }
		</div>
	);
}
