<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Relance le recalcul des alertes quand l'état du réseau lu par les règles change (écart E7 du plan M4) : mises à
 * jour disponibles, thèmes installés, quotas d'envoi, adresse du site principal. Sans lui, l'alerte « mises à jour
 * en attente » survivrait jusqu'au lendemain à la mise à jour de l'extension.
 *
 * La suppression d'un transient de mises à jour n'est pas un changement : WordPress le reconstruit juste après, et
 * c'est cette reconstruction qui déclenche la comparaison, avec la vraie liste.
 *
 * Chaque gestionnaire coûte la lecture de l'état (transients et options du réseau, liste des thèmes en cache) ; le
 * recalcul lui-même tourne en cron, par lots bornés.
 */
final class NetworkStateWatcher {

	/**
	 * Option réseau : empreinte de l'état avec lequel le dernier recalcul a été demandé.
	 */
	public const OPTION = 'msradar_network_state';

	private const UPDATE_TRANSIENTS = [ 'update_plugins', 'update_themes' ];
	private const QUOTA_OPTIONS     = [ 'upload_space_check_disabled', 'blog_upload_space' ];

	private NetworkState $state;

	public function __construct( NetworkState $state ) {
		$this->state = $state;
	}

	public function register(): void {
		foreach ( self::UPDATE_TRANSIENTS as $transient ) {
			add_action( 'set_site_transient_' . $transient, [ $this, 'check' ] );
		}
		add_action( 'deleted_theme', [ $this, 'check' ] );
		add_action( 'upgrader_process_complete', [ $this, 'check' ] );
		foreach ( self::QUOTA_OPTIONS as $option ) {
			add_action( 'add_site_option_' . $option, [ $this, 'check' ] );
			add_action( 'update_site_option_' . $option, [ $this, 'check' ] );
			add_action( 'delete_site_option_' . $option, [ $this, 'check' ] );
		}
		add_action( 'update_option_home', [ $this, 'on_home_changed' ] );
	}

	/**
	 * Seule l'adresse du site principal dit si le réseau est en https.
	 */
	public function on_home_changed(): void {
		if ( is_main_site() ) {
			$this->check();
		}
	}

	/**
	 * Compare l'état du réseau courant à celui du dernier recalcul demandé ; s'il a changé, le recalcul repart du
	 * premier site.
	 */
	public function check(): void {
		if ( ! Schema::is_current() ) {
			return;
		}
		$this->state->reset();
		$signature = $this->state->signature();
		if ( get_site_option( self::OPTION, '' ) === $signature ) {
			return;
		}
		update_site_option( self::OPTION, $signature );
		delete_site_option( Queue::RECOMPUTE_CURSOR );
		MainSite::schedule_once( Queue::HOOK_RECOMPUTE );
	}
}
