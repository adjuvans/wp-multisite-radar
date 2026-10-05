import { SelectControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pageUrl } from '../admin/config';
import { useResource } from '../hooks/use-resource';
import { buildPath } from '../store/paths';
import { formatDateTime } from '../utils/format';
import ErrorNotice from './error-notice';
import Pager from './pager';
import Skeleton from './skeleton';

/**
 * Options du filtre par type de changement (types de la spec §3.1).
 */
export function eventTypes() {
	return [
		{ value: '', label: __( 'All changes', 'multisite-radar' ) },
		{
			value: 'site_created',
			label: __( 'Sites created', 'multisite-radar' ),
		},
		{
			value: 'site_deleted',
			label: __( 'Sites deleted', 'multisite-radar' ),
		},
		{
			value: 'plugin_activated',
			label: __( 'Plugins activated', 'multisite-radar' ),
		},
		{
			value: 'plugin_deactivated',
			label: __( 'Plugins deactivated', 'multisite-radar' ),
		},
		{
			value: 'theme_switched',
			label: __( 'Themes switched', 'multisite-radar' ),
		},
		{ value: 'alert_raised', label: __( 'New alerts', 'multisite-radar' ) },
		{
			value: 'alert_resolved',
			label: __( 'Resolved alerts', 'multisite-radar' ),
		},
	];
}

function SiteCell( { site } ) {
	if ( ! site ) {
		return __( 'Whole network', 'multisite-radar' );
	}
	// Un site supprimé n'a plus d'administration : son nom reste, sans lien.
	return site.admin_url ? (
		<a href={ pageUrl( 'sites', { site: site.id } ) }>{ site.name }</a>
	) : (
		site.name
	);
}

/**
 * Le journal des changements (GET /events) : date, site et changement, les plus récents d'abord. La version
 * compacte (Vue d'ensemble, fiche d'un site) n'a ni filtre ni pagination ; la liste d'un site n'a pas de colonne Site.
 *
 * @param {Object}  props
 * @param {number}  props.site    ID du site, 0 pour tout le réseau.
 * @param {number}  props.perPage Changements par page.
 * @param {boolean} props.compact Sans filtre ni pagination.
 */
export default function EventsList( {
	site = 0,
	perPage = 20,
	compact = false,
} ) {
	const typeId = useInstanceId( EventsList, 'msradar-events-type' );
	// La page suit le site affiché : un autre site repart de la première page, sans lire la page de l'ancien.
	const [ paging, setPaging ] = useState( { site, page: 1 } );
	const page = paging.site === site ? paging.page : 1;
	const setPage = ( next ) => setPaging( { site, page: next } );
	const [ type, setType ] = useState( '' );
	const events = useResource(
		buildPath( '/events', {
			page,
			per_page: perPage,
			site: site || undefined,
			type,
		} )
	);

	const filter = ! compact && (
		<SelectControl
			id={ typeId }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Kind of change', 'multisite-radar' ) }
			value={ type }
			options={ eventTypes() }
			onChange={ ( value ) => {
				setType( value );
				setPage( 1 );
			} }
		/>
	);

	let body;
	if ( events.error ) {
		body = <ErrorNotice error={ events.error } onRetry={ events.retry } />;
	} else if ( ! events.data ) {
		body = (
			<Skeleton
				lines={ 3 }
				label={ __( 'Loading the changes…', 'multisite-radar' ) }
			/>
		);
	} else if ( events.data.length === 0 ) {
		body = (
			<p>
				{ type
					? __(
							'No change of this kind recorded yet.',
							'multisite-radar'
						)
					: __( 'No change recorded yet.', 'multisite-radar' ) }
			</p>
		);
	} else {
		body = (
			<>
				<table
					className="widefat striped msradar-table msradar-events"
					aria-busy={ events.isLoading }
				>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Date', 'multisite-radar' ) }
							</th>
							{ ! site && (
								<th scope="col">
									{ __( 'Site', 'multisite-radar' ) }
								</th>
							) }
							<th scope="col">
								{ __( 'Change', 'multisite-radar' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ events.data.map( ( event ) => (
							<tr key={ event.id }>
								<td>{ formatDateTime( event.created_gmt ) }</td>
								{ ! site && (
									<td>
										<SiteCell site={ event.site } />
									</td>
								) }
								<td>{ event.message }</td>
							</tr>
						) ) }
					</tbody>
				</table>
				{ ! compact && (
					<Pager
						page={ page }
						pages={ events.totalPages || 1 }
						onChange={ setPage }
					/>
				) }
			</>
		);
	}

	return (
		<div className="msradar-events-list">
			{ filter }
			{ body }
		</div>
	);
}
