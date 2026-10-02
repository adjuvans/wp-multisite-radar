import { __ } from '@wordpress/i18n';

/**
 * Squelette de chargement (spec §6.4) : quelques lignes grises à la place du contenu attendu. Les lecteurs d'écran
 * entendent le libellé.
 *
 * @param {Object} props
 * @param {number} props.lines Nombre de lignes.
 * @param {string} props.label Texte annoncé (par défaut « Loading… »).
 */
export default function Skeleton( { lines = 3, label } ) {
	return (
		<div className="msradar-skeleton" role="status" aria-busy="true">
			<span className="screen-reader-text">
				{ label || __( 'Loading…', 'multisite-radar' ) }
			</span>
			{ Array.from( { length: lines }, ( _, index ) => (
				<span
					key={ index }
					className="msradar-skeleton__line"
					aria-hidden="true"
				/>
			) ) }
		</div>
	);
}
