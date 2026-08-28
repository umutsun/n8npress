<?php
/**
 * Unit tests for find-replace across Elementor link controls.
 *
 * Regression cover for the tapadum 2026-08-28 report, finding 5: link fields are
 * arrays ({url, is_external, nofollow}), so neither the "text" scope (which reads
 * the widget text map) nor the "styles" scope (which only visits scalar settings)
 * ever saw them. On a multilingual site that made bulk-fixing source-language URLs
 * in translated copies impossible.
 */

namespace LuwiPress\Tests\Unit;

require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-elementor.php';

class ElementorLinkReplaceTest extends TestCase {

	private function replacer( $find, $replace ) {
		return function ( $value ) use ( $find, $replace ) {
			return str_replace( $find, $replace, $value );
		};
	}

	public function test_replaces_url_in_a_top_level_link_control(): void {
		$settings = array(
			'heading' => 'I liutai',
			'cta_url' => array( 'url' => 'https://tapadum.com/luthiers/', 'is_external' => '' ),
		);

		$count = \LuwiPress_Elementor::replace_in_link_fields(
			$settings, $this->replacer( 'https://tapadum.com/luthiers/', 'https://tapadum.com/it/luthiers/' )
		);

		$this->assertSame( 1, $count );
		$this->assertSame( 'https://tapadum.com/it/luthiers/', $settings['cta_url']['url'] );
		$this->assertSame( '', $settings['cta_url']['is_external'], 'sibling keys must be preserved' );
		$this->assertSame( 'I liutai', $settings['heading'], 'text fields are not this scope' );
	}

	public function test_replaces_urls_inside_repeater_rows(): void {
		$settings = array(
			'pills' => array(
				array( 'label' => 'Ouds', 'url' => array( 'url' => '/luthiers/?t=oud' ) ),
				array( 'label' => 'Bağlama', 'url' => array( 'url' => '/luthiers/?t=baglama' ) ),
			),
		);

		$count = \LuwiPress_Elementor::replace_in_link_fields( $settings, $this->replacer( '/luthiers/', '/it/luthiers/' ) );

		$this->assertSame( 2, $count );
		$this->assertSame( '/it/luthiers/?t=oud', $settings['pills'][0]['url']['url'] );
		$this->assertSame( '/it/luthiers/?t=baglama', $settings['pills'][1]['url']['url'] );
		$this->assertSame( 'Ouds', $settings['pills'][0]['label'] );
	}

	public function test_counts_only_actual_changes(): void {
		$settings = array(
			'a_url' => array( 'url' => 'https://tapadum.com/it/blog/' ),
			'b_url' => array( 'url' => 'https://tapadum.com/luthiers/' ),
		);

		$count = \LuwiPress_Elementor::replace_in_link_fields(
			$settings, $this->replacer( 'https://tapadum.com/luthiers/', 'https://tapadum.com/it/luthiers/' )
		);

		$this->assertSame( 1, $count );
		$this->assertSame( 'https://tapadum.com/it/blog/', $settings['a_url']['url'] );
	}

	public function test_leaves_non_link_arrays_alone(): void {
		// A typography control is an array too, but it carries no url key.
		$settings = array(
			'typography_font_size' => array( 'size' => 18, 'unit' => 'px' ),
			'tabs'                 => array( array( 'tab_title' => 'https://tapadum.com/luthiers/' ) ),
		);
		$before = $settings;

		$count = \LuwiPress_Elementor::replace_in_link_fields(
			$settings, $this->replacer( 'https://tapadum.com/luthiers/', 'X' )
		);

		$this->assertSame( 0, $count );
		$this->assertSame( $before, $settings );
	}

	public function test_ignores_non_string_url_values(): void {
		$settings = array( 'weird' => array( 'url' => array( 'nested' => 'x' ) ) );
		$before   = $settings;

		$this->assertSame( 0, \LuwiPress_Elementor::replace_in_link_fields( $settings, $this->replacer( 'x', 'y' ) ) );
		$this->assertSame( $before, $settings );
	}

	public function test_empty_settings_is_a_noop(): void {
		$settings = array();
		$this->assertSame( 0, \LuwiPress_Elementor::replace_in_link_fields( $settings, $this->replacer( 'a', 'b' ) ) );
	}
}
