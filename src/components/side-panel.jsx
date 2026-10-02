import { Button } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Panneau latéral au-dessus de la liste (fiche d'un site, sites d'un plugin ou d'un thème).
 * Le focus va sur le titre à l'ouverture et à chaque changement de contenu, puis revient à l'élément d'origine à la
 * fermeture ; Échap ferme.
 *
 * @param {Object}        props
 * @param {string}        props.title    Titre, qui nomme le dialogue.
 * @param {string|number} props.focusKey Change avec le contenu (autre site) : le focus revient alors au titre.
 * @param {?Element}      props.actions  Boutons placés avant « Close ».
 * @param {() => void}    props.onClose  Ferme le panneau.
 * @param {Element}       props.children Contenu.
 */
export default function SidePanel( {
	title,
	focusKey,
	actions = null,
	onClose,
	children,
} ) {
	const heading = useRef();
	const opener = useRef( null );

	useEffect( () => {
		const node = heading.current;
		// Capturé avant le premier déplacement du focus, pour le rendre à la fermeture.
		if ( opener.current === null ) {
			opener.current = node.ownerDocument.activeElement;
		}
		node.focus();
	}, [ focusKey ] );
	useEffect(
		() => () => {
			if ( opener.current?.isConnected ) {
				opener.current.focus();
			}
		},
		[]
	);

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
				{ actions }
				<Button
					icon={ closeSmall }
					label={ __( 'Close', 'multisite-radar' ) }
					onClick={ onClose }
				/>
			</div>
			<div className="msradar-panel__body">{ children }</div>
		</div>
	);
}
