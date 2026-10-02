<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Query\InventoryList;

defined( 'ABSPATH' ) || exit;

/**
 * État du réseau courant dont dépendent certaines règles : thèmes installés, mises à jour disponibles, quotas d'envoi,
 * https du site principal. C'est le « contexte de l'évaluateur » de la spec (§4.1) : lu une fois par passe, jamais
 * site par site, et sans appel externe (écart E4 du plan M4).
 */
final class NetworkState {

	/**
	 * Quota par défaut de get_space_allowed(), en Mo.
	 */
	private const DEFAULT_QUOTA_MB = 100;

	/**
	 * @var array<string, true>|null
	 */
	private ?array $themes = null;

	/**
	 * @var array<string, string>|null
	 */
	private ?array $plugin_updates = null;

	/**
	 * @var array<string, string>|null
	 */
	private ?array $theme_updates = null;

	private ?bool $https = null;

	public function reset(): void {
		$this->themes         = null;
		$this->plugin_updates = null;
		$this->theme_updates  = null;
		$this->https          = null;
	}

	/**
	 * Dossiers des thèmes installés, y compris ceux en erreur (leur dossier existe ; un parent manquant est signalé
	 * à part).
	 *
	 * @return array<string, true>
	 */
	public function installed_themes(): array {
		if ( null === $this->themes ) {
			$this->themes = array_fill_keys( array_map( 'strval', array_keys( wp_get_themes( [ 'errors' => null ] ) ) ), true );
		}
		return $this->themes;
	}

	/**
	 * @return array<string, string> Fichier du plugin => nouvelle version.
	 */
	public function plugin_updates(): array {
		return $this->plugin_updates ??= InventoryList::updates( 'update_plugins' );
	}

	/**
	 * @return array<string, string> Dossier du thème => nouvelle version.
	 */
	public function theme_updates(): array {
		return $this->theme_updates ??= InventoryList::updates( 'update_themes' );
	}

	/**
	 * Le réseau est en https si l'adresse de son site principal l'est (écart E12 du plan M4).
	 */
	public function uses_https(): bool {
		if ( null === $this->https ) {
			$home        = (string) get_blog_option( get_main_site_id(), 'home' );
			$this->https = 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
		}
		return $this->https;
	}

	/**
	 * Comme is_upload_space_available() du cœur : l'option upload_space_check_disabled coupe les quotas.
	 */
	public function quotas_enabled(): bool {
		return ! get_site_option( 'upload_space_check_disabled' );
	}

	/**
	 * Quota du réseau en Mo, comme get_space_allowed() du cœur, sans son filtre (écart E11 du plan M4).
	 */
	public function default_quota_mb(): int {
		$value = get_site_option( 'blog_upload_space' );
		return is_numeric( $value ) ? (int) $value : self::DEFAULT_QUOTA_MB;
	}

	/**
	 * Empreinte de tout ce qui précède. Quand elle change, les alertes du réseau doivent être recalculées.
	 * La date de la dernière vérification des mises à jour n'en fait pas partie.
	 */
	public function signature(): string {
		$plugins    = $this->plugin_updates();
		$themes     = $this->theme_updates();
		$theme_dirs = array_keys( $this->installed_themes() );
		ksort( $plugins );
		ksort( $themes );
		sort( $theme_dirs );
		return md5( (string) wp_json_encode( [ $plugins, $themes, $theme_dirs, $this->uses_https(), $this->quotas_enabled(), $this->default_quota_mb() ] ) );
	}
}
