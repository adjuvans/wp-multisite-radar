<?php
namespace MultisiteRadar\Tests;

/**
 * Les traductions livrées (languages/*.po) couvrent toutes les chaînes du catalogue et gardent leurs
 * marqueurs : un « %s » perdu ou en trop casserait sprintf() à l'affichage.
 */
final class TranslationsTest extends TestCase {

	private const PLACEHOLDER = '/%(?:\d+\$)?[sdfu]/';

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_po_files(): array {
		$files = glob( MSRADAR_DIR . 'languages/multisite-radar-*.po' );
		$cases = [];
		foreach ( false === $files ? [] : $files as $file ) {
			$cases[ basename( $file ) ] = [ $file ];
		}
		return $cases;
	}

	private static function load( string $file ): \PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$po = new \PO();
		self::assertTrue( $po->import_from_file( $file ), "$file is readable." );
		return $po;
	}

	/**
	 * @return string[]
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( self::PLACEHOLDER, $text, $matches );
		$found = $matches[0];
		sort( $found );
		return $found;
	}

	public function test_french_is_shipped(): void {
		$this->assertArrayHasKey( 'multisite-radar-fr_FR.po', $this->provide_po_files() );
	}

	/**
	 * @dataProvider provide_po_files
	 */
	public function test_every_catalogue_string_is_translated( string $file ): void {
		$pot = self::load( MSRADAR_DIR . 'languages/multisite-radar.pot' );
		$po  = self::load( $file );

		$missing = [];
		foreach ( $pot->entries as $key => $entry ) {
			$translation = $po->entries[ $key ] ?? null;
			$forms       = null === $translation ? [] : array_filter( $translation->translations, 'strlen' );
			if ( count( $forms ) < ( $entry->is_plural ? 2 : 1 ) ) {
				$missing[] = $entry->singular;
			}
		}
		$this->assertSame( [], $missing, 'Run "make i18n" and translate these strings.' );
	}

	/**
	 * @dataProvider provide_po_files
	 */
	public function test_translations_keep_their_placeholders( string $file ): void {
		$broken = [];
		foreach ( self::load( $file )->entries as $entry ) {
			$forms = array_values( $entry->translations );
			if ( [] === $forms ) {
				continue;
			}
			if ( ! $entry->is_plural ) {
				if ( self::placeholders( $forms[0] ) !== self::placeholders( $entry->singular ) ) {
					$broken[] = $entry->singular;
				}
				continue;
			}
			// La forme du singulier peut omettre le nombre (« Un site ») ; celle du pluriel garde tout.
			$allowed = array_unique( array_merge( self::placeholders( $entry->singular ), self::placeholders( (string) $entry->plural ) ) );
			if ( array_diff( self::placeholders( $forms[0] ), $allowed ) || self::placeholders( $forms[1] ?? '' ) !== self::placeholders( (string) $entry->plural ) ) {
				$broken[] = $entry->singular;
			}
		}
		$this->assertSame( [], $broken );
	}
}
