/**
 * Clic sur une ligne du tableau DataViews : le détail s'ouvre, sauf sur un élément interactif (case à cocher, lien,
 * bouton, menu, titre déjà cliquable), avec une touche de sélection (Ctrl, Cmd, Maj, Alt), avec un autre bouton que
 * le principal, ou quand l'utilisateur vient de sélectionner du texte. Le titre, bouton de DataViews, suit la même
 * règle pour la sélection de texte (keepsTextSelection).
 */
export const ITEM_ATTRIBUTE = 'data-msradar-item';

const INTERACTIVE =
	'a, button, input, select, textarea, label, summary, [role="button"], [role="checkbox"], [role="menuitem"], [role="link"], [contenteditable="true"]';
const ROW = 'tr.dataviews-view-table__row';
const TITLE = '.dataviews-title-field';

/**
 * Sélection de texte en cours dans la page de l'élément, ou null si elle est vide ou réduite à un point.
 *
 * @param {Element} target Élément cliqué.
 * @return {?Selection} Sélection non vide.
 */
function textSelection( target ) {
	const selection = target.ownerDocument?.defaultView?.getSelection?.();
	return selection &&
		! selection.isCollapsed &&
		selection.toString().trim() !== ''
		? selection
		: null;
}

/**
 * Vrai si la sélection touche le nœud : elle y commence, y finit ou le traverse.
 *
 * @param {Selection} selection Sélection non vide.
 * @param {Node}      node      Nœud.
 * @return {boolean} La sélection et le nœud se recouvrent.
 */
function touches( selection, node ) {
	if ( typeof selection.containsNode === 'function' ) {
		return selection.containsNode( node, true );
	}
	return [ selection.anchorNode, selection.focusNode ].some(
		( end ) => !! end && node.contains( end )
	);
}

/**
 * Vrai quand un clic doit s'arrêter avant DataViews (phase de capture) pour garder le texte que l'utilisateur vient
 * de sélectionner dans la ligne : sans cela, le bouton du titre ouvrirait le détail et la sélection disparaîtrait.
 * Les clics avec Ctrl, Cmd ou Maj restent à DataViews (sélection des lignes) ; les autres éléments interactifs
 * (case à cocher, lien, menu des actions) gardent leur clic.
 *
 * @param {Object} event Événement de clic (React ou DOM).
 * @return {boolean} Le clic ne doit pas aller plus loin.
 */
export function keepsTextSelection( event ) {
	if ( event.ctrlKey || event.metaKey || event.shiftKey ) {
		return false;
	}
	const target = event.target;
	if ( ! target || typeof target.closest !== 'function' ) {
		return false;
	}
	const interactive = target.closest( INTERACTIVE );
	if ( interactive && ! interactive.matches( TITLE ) ) {
		return false;
	}
	const row = target.closest( ROW );
	const selection = row ? textSelection( target ) : null;
	return !! selection && touches( selection, row );
}

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
	if ( textSelection( target ) ) {
		return null;
	}
	const row = target.closest( ROW );
	const marker = row ? row.querySelector( `[${ ITEM_ATTRIBUTE }]` ) : null;
	return marker ? marker.getAttribute( ITEM_ATTRIBUTE ) : null;
}
