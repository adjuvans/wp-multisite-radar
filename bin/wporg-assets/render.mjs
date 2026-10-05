#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Fichiers de WordPress.org (dossier assets du SVN) produits depuis les sources SVG de ce dossier, avec le Chromium de
 * Playwright : icon.svg tel quel, icônes 128 et 256 px, bannières 772×250 et 1544×500, dans .wordpress-org/.
 * Usage : npm run wporg:assets
 */
import { copyFileSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const OUT = join( HERE, '../../.wordpress-org' );
const OUTPUTS = [
	[ 'icon.svg', 'icon-128x128.png', 128, 128 ],
	[ 'icon.svg', 'icon-256x256.png', 256, 256 ],
	[ 'banner.svg', 'banner-772x250.png', 772, 250 ],
	[ 'banner.svg', 'banner-1544x500.png', 1544, 500 ],
];

mkdirSync( OUT, { recursive: true } );
copyFileSync( join( HERE, 'icon.svg' ), join( OUT, 'icon.svg' ) );

const browser = await chromium.launch();
try {
	const page = await browser.newPage();
	for ( const [ source, target, width, height ] of OUTPUTS ) {
		const svg = readFileSync( join( HERE, source ) ).toString( 'base64' );
		await page.setViewportSize( { width, height } );
		await page.setContent(
			`<!doctype html><html><body style="margin:0"><img alt="" width="${ width }" height="${ height }" src="data:image/svg+xml;base64,${ svg }"></body></html>`
		);
		await page.locator( 'img' ).evaluate( ( img ) => img.decode() );
		await page.screenshot( {
			path: join( OUT, target ),
			clip: { x: 0, y: 0, width, height },
		} );
		console.log( `.wordpress-org/${ target }` );
	}
} finally {
	await browser.close();
}
