import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

export default defineConfig( {
	// Le paquet est épinglé par le plan ; on coupe seulement l'avertissement qui recommande plugin-react.
	plugins: [ react( { disableOxcRecommendation: true } ) ],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		include: [ 'src/**/test/*.test.{js,jsx}' ],
		setupFiles: [ './tests/js/setup.mjs' ],
	},
} );
