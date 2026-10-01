import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

export default defineConfig( {
	plugins: [ react() ],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		include: [ 'src/**/test/*.test.{js,jsx}' ],
		setupFiles: [ './tests/js/setup.mjs' ],
	},
} );
