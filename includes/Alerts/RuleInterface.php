<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Storage\SiteRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Une règle d'alerte. Elle ne lit que les données stockées (aucune requête) : tout recalculer reste instantané.
 */
interface RuleInterface {

	public function id(): string;

	public function label(): string;

	public function description(): string;

	public function default_severity(): string;

	/**
	 * Schéma JSON des paramètres (objet), utilisé pour la validation et le formulaire de réglages.
	 */
	public function params_schema(): array;

	public function default_params(): array;

	public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert;

	public function message( array $args ): string;
}
