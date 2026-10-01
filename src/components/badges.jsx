import { __, _n, sprintf } from '@wordpress/i18n';

export function severityLabels() {
	return {
		error: __( 'Error', 'multisite-radar' ),
		warning: __( 'Warning', 'multisite-radar' ),
		info: __( 'Info', 'multisite-radar' ),
		none: __( 'No alert', 'multisite-radar' ),
	};
}

/**
 * Gravité maximale d'un site (ou d'une alerte), avec le nombre d'alertes.
 *
 * @param {Object} props
 * @param {string} props.level error, warning, info ou none.
 * @param {number} props.count Nombre d'alertes (0 : non affiché).
 */
export function SeverityBadge( { level, count = 0 } ) {
	const labels = severityLabels();
	const known = labels[ level ] ? level : 'none';
	const text =
		count > 0
			? sprintf(
					/* translators: 1: severity label, 2: number of alerts. */
					_n(
						'%1$s · %2$d alert',
						'%1$s · %2$d alerts',
						count,
						'multisite-radar'
					),
					labels[ known ],
					count
				)
			: labels[ known ];
	return (
		<span className={ `msradar-badge msradar-badge--${ known }` }>
			{ text }
		</span>
	);
}

/**
 * Badge « Not verified » quand les types de contenu n'ont pas été relevés dans le contexte du site (spec §3.4).
 *
 * @param {Object} props
 * @param {string} props.status fresh, stale ou missing.
 */
export function RegistryBadge( { status } ) {
	if ( status !== 'stale' && status !== 'missing' ) {
		return null;
	}
	return (
		<span className="msradar-badge msradar-badge--unverified">
			{ __( 'Not verified', 'multisite-radar' ) }
		</span>
	);
}
