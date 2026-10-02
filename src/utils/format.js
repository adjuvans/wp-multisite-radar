import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

const EMPTY = '—';

/**
 * Les champs REST *_gmt n'ont pas de décalage (convention du cœur) : ils sont en UTC.
 *
 * @param {?string} value Date REST.
 */
export function parseGmt( value ) {
	if ( ! value ) {
		return null;
	}
	const iso = /(Z|[+-]\d{2}:\d{2})$/.test( value ) ? value : `${ value }Z`;
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? null : date;
}

export function formatDateTime( value ) {
	const date = parseGmt( value );
	return date ? dateI18n( getSettings().formats.datetime, date ) : EMPTY;
}

export function formatRelative( value, now = new Date() ) {
	const date = parseGmt( value );
	return date ? humanTimeDiff( date, now ) : EMPTY;
}

function locale() {
	return document.documentElement.lang || undefined;
}

export function formatNumber( value ) {
	return value === null || value === undefined
		? EMPTY
		: new Intl.NumberFormat( locale() ).format( value );
}

export function formatBytes( bytes ) {
	if ( bytes === null || bytes === undefined ) {
		return EMPTY;
	}
	const units = [
		( size ) =>
			/* translators: %s: size in bytes. */
			sprintf( __( '%s B', 'multisite-radar' ), size ),
		( size ) =>
			/* translators: %s: size in kilobytes. */
			sprintf( __( '%s KB', 'multisite-radar' ), size ),
		( size ) =>
			/* translators: %s: size in megabytes. */
			sprintf( __( '%s MB', 'multisite-radar' ), size ),
		( size ) =>
			/* translators: %s: size in gigabytes. */
			sprintf( __( '%s GB', 'multisite-radar' ), size ),
		( size ) =>
			/* translators: %s: size in terabytes. */
			sprintf( __( '%s TB', 'multisite-radar' ), size ),
	];
	let value = Number( bytes );
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit += 1;
	}
	return units[ unit ](
		new Intl.NumberFormat( locale(), {
			maximumFractionDigits: unit === 0 ? 0 : 1,
		} ).format( value )
	);
}

/**
 * Espace disque d'un site. Une mesure arrêtée par son budget de temps est un minimum (écart E5 du plan M4).
 *
 * @param {?number} bytes    Octets mesurés.
 * @param {boolean} estimate Mesure partielle.
 */
export function formatDisk( bytes, estimate = false ) {
	const size = formatBytes( bytes );
	if ( ! estimate || bytes === null || bytes === undefined ) {
		return size;
	}
	/* translators: %s: disk size, such as "1.2 GB". */
	return sprintf( __( 'at least %s', 'multisite-radar' ), size );
}

export function displayUrl( url ) {
	return ( url || '' ).replace( /^https?:\/\//, '' ).replace( /\/$/, '' );
}
