<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Une ability en lecture seule de Multisite Radar (spec §5.4) : nom, textes, schémas et exécution. Registrar y ajoute
 * la catégorie, la permission et les métadonnées communes.
 */
abstract class Ability {

	/**
	 * Nom sans l'espace de noms, ex. « list-sites ».
	 */
	abstract public function slug(): string;

	abstract public function label(): string;

	/**
	 * Ce que fait l'ability et quand s'en servir : c'est ce qu'un assistant IA lit pour choisir ses outils.
	 */
	abstract public function description(): string;

	abstract public function input_schema(): array;

	abstract public function output_schema(): array;

	/**
	 * @param array $input Entrée validée par le cœur. En GET, WordPress 6.9 laisse les nombres en chaînes : convertir.
	 * @return array|WP_Error
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	abstract protected function run( array $input );

	/**
	 * execute_callback. Une lecture qui échoue devient une erreur 500, jamais une liste vide.
	 *
	 * @param mixed $input Entrée de l'ability : tableau, objet vide par défaut, ou null.
	 * @return array|WP_Error
	 */
	public function execute( $input = null ) {
		try {
			return $this->run( (array) $input );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', static::class, $error );
			return new WP_Error( 'msradar_storage_error', __( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * permission_callback : la capacité des pages et des routes REST de lecture.
	 */
	public function can_run(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	/**
	 * Schéma d'entrée : un objet, sans clé inconnue. Sans propriété obligatoire, l'entrée peut être omise (objet vide
	 * par défaut). Un objet sans propriété n'a pas de clé « properties » : vide, elle serait encodée [] en JSON.
	 *
	 * @param array    $properties Propriétés, chacune avec sa description.
	 * @param string[] $required   Propriétés obligatoires.
	 */
	protected static function input( array $properties, array $required = [] ): array {
		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
		];
		if ( [] !== $properties ) {
			$schema['properties'] = $properties;
		}
		if ( [] === $required ) {
			$schema['default'] = (object) [];
		} else {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Page et taille de page, comme les routes REST (au plus 100 par page).
	 */
	protected static function paging(): array {
		return [
			'page'     => [
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page of results, from 1.', 'multisite-radar' ),
			],
			'per_page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
				'description' => __( 'Results per page, 100 at most.', 'multisite-radar' ),
			],
		];
	}

	protected static function page_number( array $input ): int {
		return min( SitesQuery::MAX_PAGE, max( 1, (int) ( $input['page'] ?? 1 ) ) );
	}

	protected static function page_size( array $input ): int {
		return min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	}

	/**
	 * Enveloppe d'une page (écart E3) : une ability n'a pas d'en-têtes de pagination.
	 *
	 * @param array{items: array[], total: int} $result Résultat d'un service Query.
	 */
	protected static function page( array $result, int $page, int $per_page ): array {
		return [
			'items'       => $result['items'],
			'total'       => (int) $result['total'],
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
		];
	}

	/**
	 * @param mixed $value Liste de l'entrée.
	 * @return string[]
	 */
	protected static function strings( $value ): array {
		return array_values( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) );
	}
}
