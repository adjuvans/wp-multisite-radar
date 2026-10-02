import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Pagination simple : « Previous », « Page x of y », « Next ».
 *
 * @param {Object}                 props
 * @param {number}                 props.page     Page affichée.
 * @param {number}                 props.pages    Nombre de pages.
 * @param {(page: number) => void} props.onChange Reçoit la page demandée.
 */
export default function Pager( { page, pages, onChange } ) {
	return (
		<div className="msradar-pager">
			<Button
				variant="secondary"
				accessibleWhenDisabled
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Previous', 'multisite-radar' ) }
			</Button>
			<span>
				{ sprintf(
					/* translators: 1: current page, 2: number of pages. */
					__( 'Page %1$d of %2$d', 'multisite-radar' ),
					page,
					pages
				) }
			</span>
			<Button
				variant="secondary"
				accessibleWhenDisabled
				disabled={ page >= pages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'multisite-radar' ) }
			</Button>
		</div>
	);
}
