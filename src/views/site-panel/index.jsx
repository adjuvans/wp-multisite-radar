import { Button, TabPanel } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronLeft, chevronRight, closeSmall } from '@wordpress/icons';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import AlertsTab from './alerts-tab';
import ContentTab from './content-tab';
import ExtensionsTab from './extensions-tab';
import SummaryTab from './summary-tab';
import UsersTab from './users-tab';

function tabs() {
	return [
		{ name: 'summary', title: __( 'Summary', 'multisite-radar' ) },
		{ name: 'content', title: __( 'Content', 'multisite-radar' ) },
		{ name: 'users', title: __( 'Users', 'multisite-radar' ) },
		{ name: 'extensions', title: __( 'Extensions', 'multisite-radar' ) },
		{ name: 'alerts', title: __( 'Alerts', 'multisite-radar' ) },
	];
}

function Tab( { name, site } ) {
	switch ( name ) {
		case 'content':
			return <ContentTab site={ site } />;
		case 'users':
			return <UsersTab siteId={ site.id } />;
		case 'extensions':
			return <ExtensionsTab site={ site } />;
		case 'alerts':
			return <AlertsTab site={ site } />;
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
	const heading = useRef();
	const opener = useRef( null );

	useEffect( () => {
		const node = heading.current;
		// Capturé avant le premier déplacement du focus, pour le rendre à la fermeture.
		if ( opener.current === null ) {
			opener.current = node.ownerDocument.activeElement;
		}
		node.focus();
	}, [ siteId ] );
	useEffect(
		() => () => {
			if ( opener.current?.isConnected ) {
				opener.current.focus();
			}
		},
		[]
	);

	const index = items.findIndex( ( item ) => item.id === siteId );
	const previous = index > 0 ? items[ index - 1 ] : null;
	const next =
		index >= 0 && index < items.length - 1 ? items[ index + 1 ] : null;
	const title =
		data?.name ||
		( index >= 0 ? items[ index ].name : '' ) ||
		/* translators: %d: site ID. */
		sprintf( __( 'Site #%d', 'multisite-radar' ), siteId );

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- Escape closes the dialog.
		<div
			className="msradar-panel"
			role="dialog"
			aria-modal="false"
			aria-labelledby="msradar-panel-title"
			onKeyDown={ onKeyDown }
		>
			<div className="msradar-panel__header">
				<h2
					id="msradar-panel-title"
					className="msradar-panel__title"
					tabIndex={ -1 }
					ref={ heading }
				>
					{ title }
				</h2>
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
				<Button
					icon={ closeSmall }
					label={ __( 'Close', 'multisite-radar' ) }
					onClick={ onClose }
				/>
			</div>
			<div className="msradar-panel__body">
				<ErrorNotice error={ site.error } onRetry={ site.retry } />
				{ ! data && ! site.error && (
					<div
						className="msradar-panel__placeholder"
						aria-busy="true"
					>
						{ __( 'Loading the site…', 'multisite-radar' ) }
					</div>
				) }
				{ data && (
					<TabPanel className="msradar-panel__tabs" tabs={ tabs() }>
						{ ( tab ) => <Tab name={ tab.name } site={ data } /> }
					</TabPanel>
				) }
			</div>
		</div>
	);
}
