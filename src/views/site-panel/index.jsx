import { Button, TabPanel } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronLeft, chevronRight } from '@wordpress/icons';
import ErrorNotice from '../../components/error-notice';
import SidePanel from '../../components/side-panel';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import AlertsTab from './alerts-tab';
import ContentTab from './content-tab';
import ExtensionsTab from './extensions-tab';
import HistoryTab from './history-tab';
import SummaryTab from './summary-tab';
import UsersTab from './users-tab';

function tabs() {
	return [
		{ name: 'summary', title: __( 'Summary', 'multisite-radar' ) },
		{ name: 'content', title: __( 'Content', 'multisite-radar' ) },
		{ name: 'users', title: __( 'Users', 'multisite-radar' ) },
		{ name: 'extensions', title: __( 'Extensions', 'multisite-radar' ) },
		{ name: 'alerts', title: __( 'Alerts', 'multisite-radar' ) },
		{ name: 'history', title: __( 'History', 'multisite-radar' ) },
	];
}

function Tab( { name, site } ) {
	switch ( name ) {
		case 'content':
			return <ContentTab site={ site } />;
		case 'users':
			return <UsersTab key={ site.id } siteId={ site.id } />;
		case 'extensions':
			return <ExtensionsTab site={ site } />;
		case 'alerts':
			return <AlertsTab site={ site } />;
		case 'history':
			return <HistoryTab key={ site.id } siteId={ site.id } />;
		default:
			return <SummaryTab site={ site } />;
	}
}

/**
 * Fiche d'un site, en panneau latéral au-dessus de la liste (lien profond &site=<id>).
 *
 * @param {Object}               props
 * @param {number}               props.siteId     Site affiché.
 * @param {Array}                props.items      Sites de la page courante, pour « précédent » et « suivant ».
 * @param {(id: number) => void} props.onNavigate Reçoit l'identifiant du site à afficher.
 * @param {() => void}           props.onClose    Ferme le panneau.
 */
export default function SitePanel( { siteId, items, onNavigate, onClose } ) {
	const site = useResource( buildPath( `/sites/${ siteId }` ) );
	const data = site.isFresh ? site.data : null;

	const index = items.findIndex( ( item ) => item.id === siteId );
	const previous = index > 0 ? items[ index - 1 ] : null;
	const next =
		index >= 0 && index < items.length - 1 ? items[ index + 1 ] : null;
	const title =
		data?.name ||
		( index >= 0 ? items[ index ].name : '' ) ||
		/* translators: %d: site ID. */
		sprintf( __( 'Site #%d', 'multisite-radar' ), siteId );

	return (
		<SidePanel
			title={ title }
			focusKey={ siteId }
			onClose={ onClose }
			actions={
				<>
					<Button
						icon={ chevronLeft }
						label={ __( 'Previous site', 'multisite-radar' ) }
						disabled={ ! previous }
						onClick={ () => previous && onNavigate( previous.id ) }
					/>
					<Button
						icon={ chevronRight }
						label={ __( 'Next site', 'multisite-radar' ) }
						disabled={ ! next }
						onClick={ () => next && onNavigate( next.id ) }
					/>
				</>
			}
		>
			<ErrorNotice error={ site.error } onRetry={ site.retry } />
			{ ! data && ! site.error && (
				<Skeleton
					lines={ 6 }
					label={ __( 'Loading the site…', 'multisite-radar' ) }
				/>
			) }
			{ data && (
				<TabPanel className="msradar-panel__tabs" tabs={ tabs() }>
					{ ( tab ) => <Tab name={ tab.name } site={ data } /> }
				</TabPanel>
			) }
		</SidePanel>
	);
}
