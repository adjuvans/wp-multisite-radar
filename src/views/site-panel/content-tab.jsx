import { __, sprintf } from '@wordpress/i18n';
import { RegistryBadge } from '../../components/badges';
import { formatNumber } from '../../utils/format';
import { originLabel } from '../sites/labels';

function TypesTable( { caption, items, countLabel, count, total } ) {
	if ( ! items || items.length === 0 ) {
		return (
			<p>
				{ sprintf(
					/* translators: %s: "Content types" or "Taxonomies". */
					__( '%s: none.', 'multisite-radar' ),
					caption
				) }
			</p>
		);
	}
	return (
		<table className="widefat striped msradar-table">
			<caption>{ caption }</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Name', 'multisite-radar' ) }</th>
					<th scope="col">{ __( 'Origin', 'multisite-radar' ) }</th>
					<th scope="col" className="num">
						{ countLabel }
					</th>
					{ total && (
						<th scope="col" className="num">
							{ __( 'Total', 'multisite-radar' ) }
						</th>
					) }
				</tr>
			</thead>
			<tbody>
				{ items.map( ( item ) => (
					<tr key={ item.name }>
						<th scope="row">
							{ item.label || item.name }{ ' ' }
							<code>{ item.name }</code>{ ' ' }
							{ item.verified === false && (
								<RegistryBadge status="stale" />
							) }
						</th>
						<td>{ originLabel( item.origin ) }</td>
						<td className="num">
							{ formatNumber( count( item ) ?? 0 ) }
						</td>
						{ total && (
							<td className="num">
								{ formatNumber( total( item ) ?? 0 ) }
							</td>
						) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function ContentTab( { site } ) {
	return (
		<>
			<TypesTable
				caption={ __( 'Content types', 'multisite-radar' ) }
				items={ site.post_types }
				countLabel={ __( 'Published', 'multisite-radar' ) }
				count={ ( item ) => item.publish }
				total={ ( item ) => item.total }
			/>
			<TypesTable
				caption={ __( 'Taxonomies', 'multisite-radar' ) }
				items={ site.taxonomies }
				countLabel={ __( 'Terms', 'multisite-radar' ) }
				count={ ( item ) => item.count }
			/>
		</>
	);
}
