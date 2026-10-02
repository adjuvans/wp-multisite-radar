import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { pageUrl } from '../../admin/config';
import { SeverityBadge } from '../../components/badges';
import { formatNumber } from '../../utils/format';

const LEVELS = { error: 3, warning: 2, info: 1 };

export function Tiles( { summary, inventory = null } ) {
	const tiles = [
		{
			key: 'sites',
			label: __( 'Sites', 'multisite-radar' ),
			value: summary.total_sites,
			detail:
				summary.pending_sites > 0
					? sprintf(
							/* translators: %d: number of sites not analysed yet. */
							_n(
								'%d awaiting analysis',
								'%d awaiting analysis',
								summary.pending_sites,
								'multisite-radar'
							),
							summary.pending_sites
						)
					: __( 'All analysed', 'multisite-radar' ),
			href: pageUrl( 'sites' ),
		},
		{
			key: 'error',
			label: __( 'Sites with errors', 'multisite-radar' ),
			value: summary.by_severity.error,
			href: pageUrl( 'sites', { alert_level: 'error' } ),
		},
		{
			key: 'warning',
			label: __( 'Sites with warnings', 'multisite-radar' ),
			value: summary.by_severity.warning,
			href: pageUrl( 'sites', { alert_level: 'warning' } ),
		},
		{
			key: 'info',
			label: __( 'Sites with information', 'multisite-radar' ),
			value: summary.by_severity.info,
			href: pageUrl( 'sites', { alert_level: 'info' } ),
		},
	];
	if ( inventory ) {
		const { plugins, themes } = inventory;
		tiles.push(
			{
				key: 'unused-plugins',
				label: __( 'Unused plugins', 'multisite-radar' ),
				value: plugins.unused,
				href: pageUrl( 'plugins', { status: 'unused' } ),
			},
			{
				key: 'unused-themes',
				label: __( 'Unused themes', 'multisite-radar' ),
				value: themes.unused,
				href: pageUrl( 'themes', { status: 'unused' } ),
			},
			{
				key: 'updates',
				label: __( 'Updates available', 'multisite-radar' ),
				value: plugins.updates + themes.updates,
				detail: sprintf(
					/* translators: 1: number of plugins with an update, 2: number of themes with an update. */
					__( 'Plugins: %1$d · Themes: %2$d', 'multisite-radar' ),
					plugins.updates,
					themes.updates
				),
				href: pageUrl(
					plugins.updates === 0 && themes.updates > 0
						? 'themes'
						: 'plugins',
					{ has_update: '1' }
				),
			}
		);
	}
	return (
		<ul className="msradar-tiles">
			{ tiles.map( ( tile ) => (
				<li
					key={ tile.key }
					className={ `msradar-tile msradar-tile--${ tile.key }` }
				>
					<a className="msradar-tile__link" href={ tile.href }>
						<span className="msradar-tile__value">
							{ formatNumber( tile.value ) }
						</span>
						<span className="msradar-tile__label">
							{ tile.label }
						</span>
						{ tile.detail && (
							<span className="msradar-tile__detail">
								{ tile.detail }
							</span>
						) }
					</a>
				</li>
			) ) }
		</ul>
	);
}

export function ToReview( { rules } ) {
	const items = ( rules || [] )
		.filter( ( rule ) => rule.enabled !== false && rule.count > 0 )
		.sort(
			( a, b ) =>
				( LEVELS[ b.severity ] || 0 ) - ( LEVELS[ a.severity ] || 0 ) ||
				b.count - a.count
		);
	return (
		<Card className="msradar-to-review">
			<CardHeader>
				<h2>{ __( 'To review', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				{ items.length === 0 ? (
					<p>
						{ __( 'No alert on the network.', 'multisite-radar' ) }
					</p>
				) : (
					<ul className="msradar-to-review__list">
						{ items.map( ( rule ) => (
							<li key={ rule.rule }>
								<SeverityBadge level={ rule.severity } />{ ' ' }
								<a
									href={ pageUrl( 'sites', {
										rule: rule.rule,
									} ) }
								>
									{ rule.label }
								</a>{ ' ' }
								<span className="msradar-to-review__count">
									{ sprintf(
										/* translators: %d: number of sites. */
										_n(
											'%d site',
											'%d sites',
											rule.count,
											'multisite-radar'
										),
										rule.count
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}
