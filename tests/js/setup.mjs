import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach( cleanup );

// API du navigateur absentes de jsdom, utilisées par @wordpress/components et DataViews.
if ( ! window.matchMedia ) {
	window.matchMedia = ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener() {},
		removeListener() {},
		addEventListener() {},
		removeEventListener() {},
		dispatchEvent() {
			return false;
		},
	} );
}

class NoopObserver {
	observe() {}
	unobserve() {}
	disconnect() {}
	takeRecords() {
		return [];
	}
}
window.ResizeObserver = window.ResizeObserver || NoopObserver;
window.IntersectionObserver = window.IntersectionObserver || NoopObserver;
if ( ! window.Element.prototype.scrollIntoView ) {
	window.Element.prototype.scrollIntoView = () => {};
}

// jsdom ne comprend pas le CSS imbriqué (@layer, &) que DataViews injecte dans <head> : l'erreur d'analyse,
// sans effet sur les tests, est écartée ; les autres erreurs jsdom restent affichées.
const virtualConsole = window._virtualConsole;
if ( virtualConsole ) {
	const listeners = virtualConsole.listeners( 'jsdomError' );
	virtualConsole.removeAllListeners( 'jsdomError' );
	virtualConsole.on( 'jsdomError', ( error ) => {
		if (
			! String( error?.message ).includes(
				'Could not parse CSS stylesheet'
			)
		) {
			listeners.forEach( ( listener ) => listener( error ) );
		}
	} );
}
