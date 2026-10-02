<?php
namespace MultisiteRadar\Export;

use MultisiteRadar\Query\ThemesQuery;

defined( 'ABSPATH' ) || exit;

final class ThemesExport extends InventoryExport {

	private ThemesQuery $themes;

	public function __construct( ThemesQuery $themes ) {
		$this->themes = $themes;
	}

	public function columns(): array {
		return [
			'name'               => __( 'Name', 'multisite-radar' ),
			'stylesheet'         => __( 'Theme folder', 'multisite-radar' ),
			'version'            => __( 'Version', 'multisite-radar' ),
			'parent'             => __( 'Parent theme', 'multisite-radar' ),
			'allowed_on_network' => __( 'Network enabled', 'multisite-radar' ),
			'status'             => __( 'Status', 'multisite-radar' ),
			'active_count'       => __( 'Active theme of (sites)', 'multisite-radar' ),
			'parent_count'       => __( 'Parent of the active theme of (sites)', 'multisite-radar' ),
			'sites_count'        => __( 'Sites', 'multisite-radar' ),
			'update_version'     => __( 'Update available', 'multisite-radar' ),
		];
	}

	protected function items( array $args ): array {
		return $this->themes->filtered( $args );
	}
}
