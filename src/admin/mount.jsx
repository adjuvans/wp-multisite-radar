import { createRoot } from '@wordpress/element';

/**
 * Monte une vue dans le conteneur rendu par PHP (Admin\Menu::render()).
 *
 * @param {import( "react" ).ComponentType} View Composant de la vue.
 */
export function mount( View ) {
	const container = document.getElementById( 'msradar-app' );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <View /> );
}
