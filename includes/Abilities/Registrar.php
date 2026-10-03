<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre la catégorie et les abilities de Multisite Radar (spec §5.4), sur le site principal seulement et si
 * l'Abilities API est là. Toutes sont en lecture seule et visibles en REST ; l'exposition MCP suit le réglage
 * integrations.mcp_public, désactivé par défaut (écart E2).
 */
final class Registrar {

	public const CATEGORY = 'multisite-radar';

	private Settings $settings;

	/**
	 * @var Ability[]
	 */
	private array $abilities;

	/**
	 * @param Ability[] $abilities Abilities, dans l'ordre d'enregistrement.
	 */
	public function __construct( Settings $settings, array $abilities ) {
		$this->settings  = $settings;
		$this->abilities = $abilities;
	}

	public static function available(): bool {
		return function_exists( 'wp_register_ability' ) && is_main_site();
	}

	/**
	 * Sur wp_abilities_api_categories_init.
	 */
	public function register_category(): void {
		if ( ! self::available() ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Multisite Radar', 'multisite-radar' ),
				'description' => __( 'Read-only audit of the sites, plugins, themes and alerts of this multisite network.', 'multisite-radar' ),
			]
		);
	}

	/**
	 * Sur wp_abilities_api_init.
	 */
	public function register_abilities(): void {
		if ( ! self::available() ) {
			return;
		}
		foreach ( $this->definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * @return array<string, array> Nom complet => arguments de wp_register_ability().
	 */
	public function definitions(): array {
		$mcp         = (bool) $this->settings->get( 'integrations.mcp_public', false );
		$definitions = [];
		foreach ( $this->abilities as $ability ) {
			$definitions[ self::CATEGORY . '/' . $ability->slug() ] = [
				'label'               => $ability->label(),
				'description'         => $ability->description(),
				'category'            => self::CATEGORY,
				'input_schema'        => $ability->input_schema(),
				'output_schema'       => $ability->output_schema(),
				'execute_callback'    => [ $ability, 'execute' ],
				'permission_callback' => [ $ability, 'can_run' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
					'mcp'          => [
						'public' => $mcp,
						'type'   => 'tool',
					],
				],
			];
		}
		return $definitions;
	}
}
