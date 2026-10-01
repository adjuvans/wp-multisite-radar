import { __, _n, sprintf } from '@wordpress/i18n';

function Missing() {
	return (
		<span className="msradar-badge msradar-badge--error">
			{ __( 'Not installed', 'multisite-radar' ) }
		</span>
	);
}

export default function ExtensionsTab( { site } ) {
	const extensions = site.extensions || {};
	const theme = extensions.theme;
	const plugins = extensions.plugins_local || [];
	const network = extensions.network_plugins_count || 0;
	return (
		<>
			<h3>{ __( 'Theme', 'multisite-radar' ) }</h3>
			{ theme ? (
				<p>
					{ theme.name } { theme.version }{ ' ' }
					{ ! theme.installed && <Missing /> }
					{ theme.template && theme.template !== theme.stylesheet && (
						<>
							{ ' — ' }
							{ sprintf(
								/* translators: %s: parent theme folder name. */
								__( 'child theme of %s', 'multisite-radar' ),
								theme.template
							) }
						</>
					) }
				</p>
			) : (
				<p>—</p>
			) }
			<h3>
				{ __( 'Plugins active on this site only', 'multisite-radar' ) }
			</h3>
			{ plugins.length === 0 ? (
				<p>{ __( 'None.', 'multisite-radar' ) }</p>
			) : (
				<ul className="msradar-list">
					{ plugins.map( ( plugin ) => (
						<li key={ plugin.file }>
							{ plugin.name } { plugin.version }{ ' ' }
							{ ! plugin.installed && <Missing /> }
						</li>
					) ) }
				</ul>
			) }
			<p>
				{ sprintf(
					/* translators: %d: number of network-activated plugins. */
					_n(
						'%d plugin is active on the whole network.',
						'%d plugins are active on the whole network.',
						network,
						'multisite-radar'
					),
					network
				) }
			</p>
		</>
	);
}
