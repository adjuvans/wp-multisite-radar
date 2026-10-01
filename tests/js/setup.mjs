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
