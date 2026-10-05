/**
 * Clic sur une ligne du tableau DataViews : le détail s'ouvre, sauf sur un élément interactif (case à cocher, lien,
 * bouton, menu, titre déjà cliquable), avec une touche de sélection (Ctrl, Cmd, Maj, Alt), avec un autre bouton que
 * le principal, ou quand l'utilisateur vient de sélectionner du texte.
 */
export const ITEM_ATTRIBUTE = 'data-msradar-item';

const INTERACTIVE =
	'a, button, input, select, textarea, label, summary, [role="button"], [role="checkbox"], [role="menuitem"], [role="link"], [contenteditable="true"]';

/**
 * Identifiant de l'élément dont la ligne a reçu le clic, ou null si le clic ne doit rien ouvrir.
 *
 * @param {Object} event Événement de clic (React ou DOM).
 * @return {?string} Valeur de l'attribut data-msradar-item de la ligne.
 */
export function rowItemId( event ) {
	if ( event.defaultPrevented || event.button !== 0 ) {
		return null;
	}
	if ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
		return null;
	}
	const target = event.target;
	if ( ! target || typeof target.closest !== 'function' ) {
		return null;
	}
	if ( target.closest( INTERACTIVE ) ) {
		return null;
	}
	const selection = target.ownerDocument?.defaultView?.getSelection?.();
	if (
		selection &&
		! selection.isCollapsed &&
		selection.toString().trim() !== ''
	) {
		return null;
	}
	const row = target.closest( 'tr.dataviews-view-table__row' );
	const marker = row ? row.querySelector( `[${ ITEM_ATTRIBUTE }]` ) : null;
	return marker ? marker.getAttribute( ITEM_ATTRIBUTE ) : null;
}
