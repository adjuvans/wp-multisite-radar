import { expect, test } from 'vitest';
import { checkReadme } from '../readme.mjs';

const PLUGIN = `<?php
/**
 * Plugin Name:       Multisite Radar
 * Description:       Network-wide audit for WordPress Multisite.
 * Version:           2.0.0-rc.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 */`;

const README = `=== Multisite Radar ===
Contributors: adjuvans, cyrilledegourcy
Tags: multisite, network, audit
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-rc.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite.

== Description ==

Text.

== Installation ==

Text.

== Frequently Asked Questions ==

= Question? =

Answer.

== Screenshots ==

1. First.
2. Second.

== Changelog ==

= 2.0.0-rc.1 =
* Change.

== Upgrade Notice ==

= 2.0.0 =
Notice.
`;

const FILES = [ 'icon.svg', 'screenshot-1.png', 'screenshot-2.png' ];

test( 'a complete readme passes', () => {
	expect( checkReadme( README, PLUGIN, FILES ) ).toEqual( [] );
} );

test.each( [
	[
		'the contributors',
		README.replace( 'adjuvans, cyrilledegourcy', 'adjuvans' ),
		/Contributors/,
	],
	[ 'more than 5 tags', README.replace( 'audit', 'audit, a, b, c' ), /Tags/ ],
	[
		'a minor version in Tested up to',
		README.replace( 'Tested up to: 7.1', 'Tested up to: 7.1.2' ),
		/Tested up to/,
	],
	[
		'a stable tag that is not the version',
		README.replace( 'Stable tag: 2.0.0-rc.1', 'Stable tag: 2.0.0' ),
		/Stable tag/,
	],
	[
		'a short description longer than 150 characters',
		README.replace(
			'Network-wide audit for WordPress Multisite.\n',
			`${ 'x'.repeat( 151 ) }\n`
		),
		/150/,
	],
	[
		'a missing section',
		README.replace( '== Installation ==', '== Setup ==' ),
		/Installation/,
	],
	[
		'a screenshot without a caption',
		README.replace( '2. Second.\n', '' ),
		/caption/,
	],
	[
		'no changelog entry for the version',
		README.replace( '= 2.0.0-rc.1 =', '= 2.0.0-beta.6 =' ),
		/changelog/,
	],
] )( 'it reports %s', ( _, readme, message ) => {
	const errors = checkReadme( readme, PLUGIN, FILES );
	expect( errors ).toHaveLength( 1 );
	expect( errors[ 0 ] ).toMatch( message );
} );

test( 'the short description must be the one of the plugin header', () => {
	expect(
		checkReadme( README, PLUGIN.replace( 'audit for', 'audit of' ), FILES )
	).toEqual( [
		'The short description must be the Description of the plugin header.',
	] );
} );

test( 'screenshots are numbered from 1 without gaps', () => {
	expect(
		checkReadme( README, PLUGIN, [
			'screenshot-1.png',
			'screenshot-3.png',
		] )
	).toContainEqual( expect.stringMatching( /without gaps/ ) );
} );
