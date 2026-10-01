import { createRoot, StrictMode } from '@wordpress/element';
import { registerCoreStore } from '../store';
import App from './app';
import { getConfig } from './config';

/**
 * Monte une vue dans le conteneur rendu par PHP (Admin\Menu::render()), avec le store hydraté par les données
 * préchargées : la première vue s'affiche sans requête.
 *
 * @param {import( "react" ).ComponentType} View Composant de la vue.
 */
export function mount( View ) {
	const container = document.getElementById( 'msradar-app' );
	if ( ! container ) {
		return;
	}
	registerCoreStore( getConfig().preload );
	createRoot( container ).render(
		<StrictMode>
			<App>
				<View />
			</App>
		</StrictMode>
	);
}
