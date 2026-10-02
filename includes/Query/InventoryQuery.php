<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Synthèse de l'inventaire pour la Vue d'ensemble et l'avis des pages Plugins et Thèmes : tant que des sites restent
 * à analyser, les plugins et thèmes qu'ils utilisent ne sont pas comptés (écart E8 du plan M3).
 */
final class InventoryQuery {

	private PluginsQuery $plugins;
	private ThemesQuery $themes;
	private SitesRepository $sites;

	public function __construct( PluginsQuery $plugins, ThemesQuery $themes, SitesRepository $sites ) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
		$this->sites   = $sites;
	}

	/**
	 * @return array{pending_sites: int, plugins: array, themes: array}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function summary(): array {
		return [
			'pending_sites' => $this->sites->count_pending( get_current_network_id() ),
			'plugins'       => $this->plugins->summary(),
			'themes'        => $this->themes->summary(),
		];
	}
}
