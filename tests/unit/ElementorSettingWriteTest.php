<?php
/**
 * Unit tests for Elementor widget setting writes.
 *
 * Regression cover for the tapadum 2026-08-25 report:
 *  - array/object values crashed the shared setter (wp_kses_post is string-only)
 *  - writing a plain string into a URL-object control silently destroyed the
 *    rendered element with no way back through the tool itself
 *  - indexed repeater paths ("tabs:0:tab_title") wrote junk flat keys
 */

namespace LuwiPress\Tests\Unit;

use Brain\Monkey\Functions;

require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-elementor.php';

class ElementorSettingWriteTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/* ── scalar values keep working exactly as before ── */

	public function test_scalar_value_is_written_as_string(): void {
		$settings = array( 'lead' => 'old' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'lead', 'düz metin' );

		$this->assertTrue( $result );
		$this->assertSame( 'düz metin', $settings['lead'] );
	}

	/* ── FINDING 5b: array / object values must not blow up the setter ── */

	public function test_repeater_array_is_written_whole(): void {
		$settings = array( 'tabs' => array( array( 'tab_title' => 'One' ) ) );
		$new      = array(
			array( 'tab_title' => 'Eins', 'tab_content' => 'Inhalt' ),
			array( 'tab_title' => 'Zwei', 'tab_content' => 'Mehr' ),
		);

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs', $new );

		$this->assertTrue( $result );
		$this->assertSame( $new, $settings['tabs'] );
	}

	public function test_url_object_is_written_whole(): void {
		$settings = array( 'cta_url' => array( 'url' => '/old/', 'is_external' => '' ) );
		$new      = array( 'url' => '/de/meister/', 'is_external' => '', 'nofollow' => '' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'cta_url', $new );

		$this->assertTrue( $result );
		$this->assertSame( $new, $settings['cta_url'] );
	}

	public function test_unsupported_value_type_returns_error_instead_of_throwing(): void {
		$settings = array( 'lead' => 'old' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'lead', new \stdClass() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsupported_value_type', $result->get_error_code() );
		$this->assertSame( 'old', $settings['lead'], 'failed write must not mutate settings' );
	}

	/* ── FINDING 5b (one-way door): string into a URL-object control ── */

	public function test_string_into_url_object_control_preserves_object_shape(): void {
		$settings = array(
			'cta_url' => array( 'url' => '/old/', 'is_external' => '', 'nofollow' => 'on' ),
		);

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'cta_url', '/de/alle-meister/' );

		$this->assertTrue( $result );
		$this->assertSame(
			array( 'url' => '/de/alle-meister/', 'is_external' => '', 'nofollow' => 'on' ),
			$settings['cta_url'],
			'a bare string must fill the url key, never replace the control object'
		);
	}

	public function test_string_into_repeater_control_is_refused(): void {
		$settings = array( 'tabs' => array( array( 'tab_title' => 'One' ) ) );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs', 'just text' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shape_mismatch', $result->get_error_code() );
		$this->assertSame(
			array( array( 'tab_title' => 'One' ) ),
			$settings['tabs'],
			'refused write must leave the repeater untouched'
		);
	}

	/* ── FINDING 5: indexed repeater paths ── */

	public function test_indexed_path_writes_into_repeater_item(): void {
		$settings = array(
			'tabs' => array(
				array( 'tab_title' => 'One', 'tab_content' => 'a' ),
				array( 'tab_title' => 'Two', 'tab_content' => 'b' ),
			),
		);

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs:1:tab_title', 'Zwei' );

		$this->assertTrue( $result );
		$this->assertSame( 'Zwei', $settings['tabs'][1]['tab_title'] );
		$this->assertSame( 'One', $settings['tabs'][0]['tab_title'] );
		$this->assertArrayNotHasKey( 'tabs:1:tab_title', $settings, 'must not write a flat junk key' );
	}

	public function test_indexed_path_out_of_range_returns_error_and_writes_nothing(): void {
		$settings = array( 'tabs' => array( array( 'tab_title' => 'One' ) ) );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs:7:tab_title', 'Sieben' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'repeater_index_missing', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'tabs:7:tab_title', $settings );
		$this->assertCount( 1, $settings['tabs'] );
	}

	public function test_indexed_path_on_missing_repeater_key_returns_error(): void {
		$settings = array( 'heading' => 'x' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'bullets:0:body', 'text' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'repeater_index_missing', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'bullets', $settings );
	}

	/* ── 2026-08-28 report #1: nested path into an object control ── */

	public function test_two_part_path_writes_into_an_object_control(): void {
		// "all_link:url" — the shape the operator actually reached for. Before, a
		// 2-segment path was rejected outright (and on 3.17.3 it created a flat
		// junk key the renderer ignores).
		$settings = array( 'all_link' => array( 'url' => '/journal', 'is_external' => '', 'nofollow' => '' ) );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'all_link:url', 'https://tapadum.com/it/blog/' );

		$this->assertTrue( $result );
		$this->assertSame(
			array( 'url' => 'https://tapadum.com/it/blog/', 'is_external' => '', 'nofollow' => '' ),
			$settings['all_link']
		);
		$this->assertArrayNotHasKey( 'all_link:url', $settings );
	}

	public function test_two_part_path_can_add_a_key_to_an_existing_object_control(): void {
		$settings = array( 'cta_url' => array( 'url' => '/x/' ) );

		$this->assertTrue( \LuwiPress_Elementor::apply_widget_setting( $settings, 'cta_url:nofollow', 'on' ) );
		$this->assertSame( array( 'url' => '/x/', 'nofollow' => 'on' ), $settings['cta_url'] );
	}

	public function test_two_part_path_on_a_scalar_field_is_refused(): void {
		$settings = array( 'heading' => 'Titel' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'heading:url', 'x' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_an_object_field', $result->get_error_code() );
		$this->assertSame( 'Titel', $settings['heading'] );
	}

	public function test_two_part_path_with_numeric_segment_is_refused_as_ambiguous(): void {
		// "tabs:0" addresses a whole repeater ROW — writing one needs a sub-field.
		$settings = array( 'tabs' => array( array( 'tab_title' => 'One' ) ) );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs:0', 'x' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_field_path', $result->get_error_code() );
	}

	public function test_two_part_path_on_a_missing_field_is_refused(): void {
		$settings = array( 'heading' => 'x' );

		$result = \LuwiPress_Elementor::apply_widget_setting( $settings, 'ghost_link:url', 'y' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_an_object_field', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'ghost_link', $settings );
	}

	/* ── 2026-08-28 report #6: HTML must survive a repeater write ── */

	public function test_html_survives_a_repeater_sub_field_write(): void {
		$settings = array( 'tabs' => array( array( 'tab_title' => 'Q', 'tab_content' => 'plain' ) ) );
		$html     = '<p>Vedi la <a href="https://tapadum.com/it/spedizioni/">pagina spedizioni</a>.</p>';

		$this->assertTrue( \LuwiPress_Elementor::apply_widget_setting( $settings, 'tabs:0:tab_content', $html ) );
		$this->assertSame( $html, $settings['tabs'][0]['tab_content'] );
	}
}
