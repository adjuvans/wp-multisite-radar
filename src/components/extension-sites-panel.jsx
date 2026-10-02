import { Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pageUrl } from '../admin/config';
import { useResource } from '../hooks/use-resource';
import { displayUrl, formatNumber } from '../utils/format';
import ErrorNotice from './error-notice';
import Pager from './pager';
import SidePanel from './side-panel';
import Skeleton from './skeleton';

const PER_PAGE = 20;

/**
 * Sites qui utilisent un plugin ou un thème (GET /plugins/{id}/sites, GET /themes/{stylesheet}/sites), paginés.
 * Chaque site mène à sa fiche dans la page Sites.
 *
 * @param {Object}                    props
 * @param {string}                    props.title    Nom du plugin ou du thème.
 * @param {(args: Object) => string}  props.path     Chemin REST d'une page de sites ({ page, per_page }).
 * @param {?string}                   props.note     Précision affichée au-dessus de la liste.
 * @param {(site: Object) => ?string} props.describe Précision sur un site.
 * @param {() => void}                props.onClose  Ferme le panneau.
 */
export default function ExtensionSitesPanel( {
	title,
	path,
	note = null,
	describe = () => null,
	onClose,
} ) {
	const [ page, setPage ] = useState( 1 );
	const sites = useResource( path( { page, per_page: PER_PAGE } ) );
	const total = sites.total || 0;
	const pages = sites.totalPages || 1;

	return (
		<SidePanel title={ title } focusKey={ title } onClose={ onClose }>
			{ note && (
				<Notice status="info" isDismissible={ false }>
					{ note }
				</Notice>
			) }
			<ErrorNotice error={ sites.error } onRetry={ sites.retry } />
			{ ! sites.data && ! sites.error && (
				<Skeleton
					lines={ 5 }
					label={ __( 'Loading sites…', 'multisite-radar' ) }
				/>
			) }
			{ sites.data && sites.data.length === 0 && (
				<p>{ __( 'No analysed site uses it.', 'multisite-radar' ) }</p>
			) }
			{ sites.data && sites.data.length > 0 && (
				<>
					<p>
						{ sprintf(
							/* translators: %s: number of sites. */
							_n(
								'%s site',
								'%s sites',
								total,
								'multisite-radar'
							),
							formatNumber( total )
						) }
					</p>
					<ul
						className="msradar-site-list"
						aria-busy={ sites.isLoading }
					>
						{ sites.data.map( ( site ) => {
							const detail = describe( site );
							return (
								<li key={ site.id }>
									<a
										href={ pageUrl( 'sites', {
											site: site.id,
										} ) }
									>
										{ site.name }
									</a>
									<span className="msradar-site-list__url">
										{ displayUrl( site.url ) }
									</span>
									{ detail && (
										<span className="msradar-site-list__detail">
											{ detail }
										</span>
									) }
								</li>
							);
						} ) }
					</ul>
					{ pages > 1 && (
						<Pager
							page={ page }
							pages={ pages }
							onChange={ setPage }
						/>
					) }
				</>
			) }
		</SidePanel>
	);
}
