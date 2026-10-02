<?php
namespace MultisiteRadar\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Une ressource exportable : ses colonnes, ses filtres (aux noms de sa route REST) et la lecture de ses éléments.
 */
interface ExportSource {

	/**
	 * @return array<string, string> Clé => en-tête traduit, dans l'ordre d'export.
	 */
	public function columns(): array;

	/**
	 * @return string[] Filtres acceptés.
	 */
	public function filters(): array;

	/**
	 * @return string[] Ceux des filtres qui sont des listes.
	 */
	public function list_filters(): array;

	/**
	 * Première lecture, avant tout envoi : une base illisible donne encore une vraie erreur 500.
	 *
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function check( array $filters ): void;

	/**
	 * Passe chaque élément filtré, réduit aux colonnes $keys dans cet ordre, à $consumer.
	 *
	 * @param string[] $keys Colonnes.
	 * @return int Nombre d'éléments.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function each( array $filters, array $keys, callable $consumer, int $chunk ): int;
}
