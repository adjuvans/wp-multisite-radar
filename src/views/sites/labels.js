import { __, sprintf } from '@wordpress/i18n';

export function statusLabels() {
	return {
		public: __( 'Public', 'multisite-radar' ),
		private: __( 'Private', 'multisite-radar' ),
		archived: __( 'Archived', 'multisite-radar' ),
		spam: __( 'Spam', 'multisite-radar' ),
		deleted: __( 'Deleted', 'multisite-radar' ),
	};
}

export function registryLabels() {
	return {
		fresh: __( 'Verified', 'multisite-radar' ),
		stale: __( 'Outdated', 'multisite-radar' ),
		missing: __( 'Never read', 'multisite-radar' ),
	};
}

/**
 * États d'un site, dans l'ordre des filtres : public ou privé, puis archivé, spam, supprimé.
 *
 * @param {Object} site Site REST.
 */
export function siteStatuses( site ) {
	const status = site.status || {};
	return [
		status.public ? 'public' : 'private',
		...[ 'archived', 'spam', 'deleted' ].filter( ( key ) => status[ key ] ),
	];
}

/**
 * Origine d'un type de contenu ou d'une taxonomie (spec §3.2 : core, plugin, mu-plugin, theme, unknown).
 *
 * @param {Object} origin { kind, slug }.
 */
export function originLabel( origin ) {
	const slug = origin?.slug || '';
	switch ( origin?.kind ) {
		case 'core':
			return __( 'WordPress', 'multisite-radar' );
		case 'plugin':
			/* translators: %s: plugin folder name. */
			return sprintf( __( 'Plugin: %s', 'multisite-radar' ), slug );
		case 'mu-plugin':
			return sprintf(
				/* translators: %s: must-use plugin name. */
				__( 'Must-use plugin: %s', 'multisite-radar' ),
				slug
			);
		case 'theme':
			/* translators: %s: theme folder name. */
			return sprintf( __( 'Theme: %s', 'multisite-radar' ), slug );
		default:
			return __( 'Unknown', 'multisite-radar' );
	}
}
