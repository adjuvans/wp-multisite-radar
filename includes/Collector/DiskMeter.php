<?php
namespace MultisiteRadar\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * Taille d'un dossier et de son contenu, parcouru sans récursion et dans un budget de temps (écart E5 du plan M4).
 *
 * recurse_dirsize() du cœur ne convient pas ici : il abandonne (null) au lieu de rendre une valeur partielle, compte le
 * temps depuis le début de la requête et écrit le transient dirsize_cache du site qui analyse.
 */
final class DiskMeter {

	/**
	 * Le chronomètre est lu avant chaque dossier, puis toutes les CHECK_EVERY entrées d'un même dossier.
	 */
	private const CHECK_EVERY = 64;

	/**
	 * @param string   $directory Dossier mesuré.
	 * @param string[] $exclude   Dossiers ignorés avec tout leur contenu (chemins absolus).
	 * @param float    $budget    Secondes disponibles ; au-delà, la mesure s'arrête et la valeur est partielle.
	 * @return array{bytes: int, complete: bool}|null Null si le chemin existe mais n'est pas un dossier lisible ;
	 *                                                un dossier absent pèse 0 octet.
	 */
	public static function measure( string $directory, array $exclude, float $budget ): ?array {
		$directory = untrailingslashit( $directory );
		if ( ! file_exists( $directory ) ) {
			return self::result( 0, true );
		}
		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return null;
		}

		$skip     = array_map( 'untrailingslashit', $exclude );
		$deadline = microtime( true ) + max( 0.0, $budget );
		$bytes    = 0;
		$pending  = [ $directory ];
		while ( [] !== $pending ) {
			if ( microtime( true ) >= $deadline ) {
				return self::result( $bytes, false );
			}
			$current = (string) array_pop( $pending );
			$entries = @scandir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable sub-folder is skipped, not reported.
			if ( false === $entries ) {
				continue;
			}
			$seen = 0;
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( is_link( $path ) ) {
					continue; // Un lien n'est pas suivi : ni boucle, ni fichier compté deux fois.
				}
				if ( is_dir( $path ) ) {
					if ( ! in_array( $path, $skip, true ) ) {
						$pending[] = $path;
					}
				} elseif ( is_file( $path ) ) {
					$size   = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted meanwhile counts for nothing.
					$bytes += false === $size ? 0 : $size;
				}
				++$seen;
				if ( 0 === $seen % self::CHECK_EVERY && microtime( true ) >= $deadline ) {
					return self::result( $bytes, false );
				}
			}
		}
		return self::result( $bytes, true );
	}

	/**
	 * @return array{bytes: int, complete: bool}
	 */
	private static function result( int $bytes, bool $complete ): array {
		return [
			'bytes'    => $bytes,
			'complete' => $complete,
		];
	}
}
