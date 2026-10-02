<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\PluginsQuery;

defined( 'ABSPATH' ) || exit;

final class PluginsExport extends InventoryExport {

	private PluginsQuery $plugins;

	public function __construct( PluginsQuery $plugins ) {
		$this->plugins = $plugins;
	}

	public function columns(): array {
		return [
			'name'           => __( 'Name', 'multisite-radar' ),
			'file'           => __( 'Plugin file', 'multisite-radar' ),
			'version'        => __( 'Version', 'multisite-radar' ),
			'status'         => __( 'Status', 'multisite-radar' ),
			'network_active' => __( 'Network activated', 'multisite-radar' ),
			'sites_count'    => __( 'Sites', 'multisite-radar' ),
			'update_version' => __( 'Update available', 'multisite-radar' ),
		];
	}

	protected function items( array $args ): array {
		return $this->plugins->filtered( $args );
	}
}
