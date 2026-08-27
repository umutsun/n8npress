<?php
/**
 * Unit tests for the protected-terms glossary.
 *
 * Regression cover for the tapadum 2026-08-25 report, finding 6:
 * the engine translated brand names ("Tapadum Music Store" -> "Tapadum
 * Musikladen", "Tapadum Booking" -> "Tapadum Buchung") and then derived the
 * page title and slug from the mistranslation.
 */

namespace LuwiPress\Tests\Unit;

use Brain\Monkey\Functions;

require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-translation.php';

class TranslationGlossaryTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	private function withOption( $value ): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $value ) {
			return 'luwipress_translation_glossary' === $name ? $value : $default;
		} );
	}

	public function test_newline_separated_terms_are_parsed(): void {
		$this->withOption( "Tapadum Music Store\nTapadum Booking\n" );

		$this->assertSame(
			array( 'Tapadum Music Store', 'Tapadum Booking' ),
			\LuwiPress_Translation::get_protected_terms()
		);
	}

	public function test_comma_separated_terms_are_parsed(): void {
		$this->withOption( 'Tapadum, Tapadum Music Store , Bağlama' );

		$this->assertSame(
			array( 'Tapadum', 'Tapadum Music Store', 'Bağlama' ),
			\LuwiPress_Translation::get_protected_terms()
		);
	}

	public function test_array_option_is_accepted_and_deduplicated(): void {
		$this->withOption( array( 'Tapadum', 'Tapadum', '', '  ', 'Oud' ) );

		$this->assertSame( array( 'Tapadum', 'Oud' ), \LuwiPress_Translation::get_protected_terms() );
	}

	public function test_empty_option_yields_no_terms(): void {
		$this->withOption( '' );

		$this->assertSame( array(), \LuwiPress_Translation::get_protected_terms() );
	}

	public function test_prompt_rule_is_empty_when_no_terms_configured(): void {
		$this->withOption( '' );

		$this->assertSame( '', \LuwiPress_Translation::glossary_prompt_rule() );
	}

	public function test_prompt_rule_lists_every_protected_term(): void {
		$this->withOption( "Tapadum Music Store\nTapadum Booking" );

		$rule = \LuwiPress_Translation::glossary_prompt_rule();

		$this->assertStringContainsString( 'Tapadum Music Store', $rule );
		$this->assertStringContainsString( 'Tapadum Booking', $rule );
		$this->assertStringContainsString( 'NEVER translate', $rule );
	}

	public function test_prompt_rule_is_capped_so_it_cannot_dominate_the_prompt(): void {
		$many = array_map( function ( $i ) { return "Term$i"; }, range( 1, 300 ) );
		$this->withOption( $many );

		$terms = \LuwiPress_Translation::get_protected_terms();

		$this->assertLessThanOrEqual( 200, count( $terms ) );
	}
}
