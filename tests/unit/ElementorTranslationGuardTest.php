<?php
/**
 * Unit tests for the Elementor translation write guards.
 *
 * Regression cover for the tapadum 2026-08-25 report:
 *  - a mostly-failed AI pass still wrote a source-language clone over a good
 *    translation (findings 1A/1B: EN copy stomping live DE content)
 *  - the pending background queue was invisible to every status endpoint
 *    (finding 2), because the Elementor path uses a different meta schema
 */

namespace LuwiPress\Tests\Unit;

require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-elementor.php';

class ElementorTranslationGuardTest extends TestCase {

	/* ── FINDING 1: coverage must be measured, not assumed ── */

	public function test_coverage_is_one_when_every_attempted_field_came_back(): void {
		$attempted = array(
			array( 'widget_id' => 'aaa', 'field' => 'title' ),
			array( 'widget_id' => 'bbb', 'field' => 'editor' ),
		);
		$trans_map = array(
			'aaa' => array( 'title' => 'Titel' ),
			'bbb' => array( 'editor' => 'Inhalt' ),
		);

		$this->assertSame( 1.0, \LuwiPress_Elementor::translation_coverage( $attempted, $trans_map ) );
	}

	public function test_coverage_reflects_partial_chunk_failure(): void {
		$attempted = array(
			array( 'widget_id' => 'aaa', 'field' => 'title' ),
			array( 'widget_id' => 'bbb', 'field' => 'editor' ),
			array( 'widget_id' => 'ccc', 'field' => 'text' ),
			array( 'widget_id' => 'ddd', 'field' => 'text' ),
		);
		// Only one chunk of four survived — this is the shape that used to be
		// treated as success and written wholesale over the live translation.
		$trans_map = array( 'aaa' => array( 'title' => 'Titel' ) );

		$this->assertSame( 0.25, \LuwiPress_Elementor::translation_coverage( $attempted, $trans_map ) );
	}

	public function test_coverage_of_nothing_attempted_is_zero(): void {
		$this->assertSame( 0.0, \LuwiPress_Elementor::translation_coverage( array(), array() ) );
	}

	public function test_coverage_ignores_translations_for_fields_never_attempted(): void {
		$attempted = array( array( 'widget_id' => 'aaa', 'field' => 'title' ) );
		$trans_map = array(
			'aaa' => array( 'title' => 'Titel' ),
			'zzz' => array( 'ghost' => 'nope' ),
		);

		$this->assertSame( 1.0, \LuwiPress_Elementor::translation_coverage( $attempted, $trans_map ) );
	}

	/* ── FINDING 1: existing target content must survive a gap ── */

	public function test_fresh_translation_wins_over_existing_target_value(): void {
		$existing = array( 'aaa' => array( 'title' => 'Alter Titel' ) );
		$value    = \LuwiPress_Elementor::resolve_translated_value(
			'aaa', 'title', array( 'aaa' => array( 'title' => 'Neuer Titel' ) ), $existing, 'Source Title'
		);

		$this->assertSame( 'Neuer Titel', $value );
	}

	public function test_untranslated_field_keeps_existing_target_value_not_source(): void {
		// The AI chunk carrying this field failed. The live page already holds a
		// good German string (auto-translated earlier, or hand-fixed by the
		// operator). Falling back to source here is the data loss we are fixing.
		$existing = array( 'bbb' => array( 'editor' => 'Handgepflegter Text' ) );
		$value    = \LuwiPress_Elementor::resolve_translated_value(
			'bbb', 'editor', array(), $existing, 'English body copy'
		);

		$this->assertSame( 'Handgepflegter Text', $value );
	}

	public function test_untranslated_field_falls_back_to_source_when_target_is_new(): void {
		$value = \LuwiPress_Elementor::resolve_translated_value(
			'ccc', 'text', array(), array(), 'English body copy'
		);

		$this->assertSame( 'English body copy', $value );
	}

	public function test_empty_existing_target_value_does_not_shadow_source(): void {
		$existing = array( 'ccc' => array( 'text' => '' ) );
		$value    = \LuwiPress_Elementor::resolve_translated_value(
			'ccc', 'text', array(), $existing, 'English body copy'
		);

		$this->assertSame( 'English body copy', $value );
	}

	public function test_collect_widget_settings_flattens_nested_elementor_tree(): void {
		$data = array(
			array(
				'id'       => 'sec1',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col1',
						'elType'   => 'column',
						'settings' => array(),
						'elements' => array(
							array(
								'id'         => 'wid1',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Überschrift' ),
							),
						),
					),
				),
			),
		);

		$map = \LuwiPress_Elementor::collect_widget_settings( $data );

		$this->assertArrayHasKey( 'wid1', $map );
		$this->assertSame( 'Überschrift', $map['wid1']['title'] );
	}

	/* ── FINDING 2: the pending queue must be readable ── */

	public function test_parse_cron_queue_extracts_pending_translation_jobs(): void {
		$cron = array(
			1756000000 => array(
				'luwipress_elementor_translate_single' => array(
					'hashkey1' => array( 'schedule' => false, 'args' => array( 44846, 'de' ) ),
				),
				'some_other_hook' => array(
					'hashkey2' => array( 'schedule' => false, 'args' => array( 1 ) ),
				),
			),
			1756000600 => array(
				'luwipress_elementor_translate_single' => array(
					'hashkey3' => array( 'schedule' => false, 'args' => array( 44891, 'fr' ) ),
				),
			),
			'version' => 2,
		);

		$entries = \LuwiPress_Elementor::parse_cron_queue( $cron, 'luwipress_elementor_translate_single' );

		$this->assertCount( 2, $entries );
		$this->assertSame( 44846, $entries[0]['post_id'] );
		$this->assertSame( 'de', $entries[0]['language'] );
		$this->assertSame( 1756000000, $entries[0]['timestamp'] );
		$this->assertSame( 44891, $entries[1]['post_id'] );
		$this->assertSame( 'fr', $entries[1]['language'] );
	}

	public function test_parse_cron_queue_returns_empty_for_empty_cron_array(): void {
		$this->assertSame(
			array(),
			\LuwiPress_Elementor::parse_cron_queue( array( 'version' => 2 ), 'luwipress_elementor_translate_single' )
		);
	}

	public function test_parse_cron_queue_skips_malformed_entries(): void {
		$cron = array(
			1756000000 => array(
				'luwipress_elementor_translate_single' => array(
					'ok'      => array( 'args' => array( 44846, 'de' ) ),
					'no_args' => array( 'schedule' => false ),
					'short'   => array( 'args' => array( 44846 ) ),
				),
			),
		);

		$entries = \LuwiPress_Elementor::parse_cron_queue( $cron, 'luwipress_elementor_translate_single' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 44846, $entries[0]['post_id'] );
	}

	/* ── FINDING 6c: widget text map must survive theme field drift ── */

	public function test_repeater_config_accepts_multiple_candidate_item_keys(): void {
		// lwp-process-steps stores rows under "items" in the shipped Gold theme but
		// the map declared "steps"; lwp-testimonials moved items -> reviews. Both
		// spellings must extract, or those sections silently stay untranslated.
		$multi = array();
		foreach ( \LuwiPress_Elementor::REPEATER_WIDGETS as $type => $config ) {
			if ( is_array( $config['items_key'] ) ) {
				$multi[ $type ] = $config['items_key'];
			}
		}
		$this->assertNotEmpty( $multi, 'expected at least one multi-key repeater config' );
		$this->assertSame( array( 'steps', 'items' ), $multi['lwp-process-steps'] ?? null );
		$this->assertSame( array( 'items', 'reviews' ), $multi['lwp-testimonials'] ?? null );
	}

	public function test_every_repeater_widget_is_also_gated_in_the_translatable_map(): void {
		// extract_translatable_text() bails on widgets missing from TRANSLATABLE_WIDGETS,
		// so a repeater-only widget absent there is never read at all.
		$missing = array_diff(
			array_keys( \LuwiPress_Elementor::REPEATER_WIDGETS ),
			array_keys( \LuwiPress_Elementor::TRANSLATABLE_WIDGETS )
		);
		$this->assertSame( array(), array_values( $missing ) );
	}

	public function test_known_drifted_fields_are_present(): void {
		$tw = \LuwiPress_Elementor::TRANSLATABLE_WIDGETS;
		$this->assertContains( 'lead', $tw['lwp-hero-split'], 'hero-split lead was reported untranslated' );
		$this->assertContains( 'title', \LuwiPress_Elementor::REPEATER_WIDGETS['lwp-info-bar']['fields'] );
		$this->assertContains( 'desc', \LuwiPress_Elementor::REPEATER_WIDGETS['lwp-info-bar']['fields'] );
		$this->assertContains( 'q', \LuwiPress_Elementor::REPEATER_WIDGETS['lwp-faq']['fields'] );
		$this->assertContains( 'a', \LuwiPress_Elementor::REPEATER_WIDGETS['lwp-faq']['fields'] );
	}

	public function test_repeater_extraction_matches_either_candidate_key(): void {
		$config = array( 'items_key' => array( 'steps', 'items' ), 'fields' => array( 'title', 'body' ) );

		$old = \LuwiPress_Elementor::extract_repeater_texts(
			array( 'steps' => array( array( 'title' => 'Step one', 'body' => 'Do this' ) ) ), $config
		);
		$new = \LuwiPress_Elementor::extract_repeater_texts(
			array( 'items' => array( array( 'title' => 'Step one', 'body' => 'Do this' ) ) ), $config
		);

		$this->assertSame( array( 'steps:0:title' => 'Step one', 'steps:0:body' => 'Do this' ), $old );
		$this->assertSame( array( 'items:0:title' => 'Step one', 'items:0:body' => 'Do this' ), $new );
	}

	public function test_repeater_extraction_still_accepts_a_plain_string_key(): void {
		$texts = \LuwiPress_Elementor::extract_repeater_texts(
			array( 'rows' => array( array( 'label' => 'Material' ), array( 'label' => 'Origin' ) ) ),
			array( 'items_key' => 'rows', 'fields' => array( 'label' ) )
		);

		$this->assertSame( array( 'rows:0:label' => 'Material', 'rows:1:label' => 'Origin' ), $texts );
	}

	public function test_repeater_extraction_skips_missing_keys_and_nonscalar_rows(): void {
		$texts = \LuwiPress_Elementor::extract_repeater_texts(
			array( 'items' => array( array( 'title' => 'ok', 'nested' => array( 'x' ) ), 'not-a-row' ) ),
			array( 'items_key' => array( 'absent', 'items' ), 'fields' => array( 'title', 'nested' ) )
		);

		$this->assertSame( array( 'items:0:title' => 'ok' ), $texts );
	}

	/* ── FINDING 6b: siblings born without a slug were never repaired ── */

	public function test_empty_slug_needs_repair(): void {
		// The old check was is_numeric($post_name); is_numeric('') is FALSE, so a
		// translation saved with no slug at all slipped through forever.
		$this->assertTrue( \LuwiPress_Elementor::slug_needs_repair( '' ) );
		$this->assertTrue( \LuwiPress_Elementor::slug_needs_repair( '   ' ) );
		$this->assertTrue( \LuwiPress_Elementor::slug_needs_repair( null ) );
	}

	public function test_numeric_slug_still_needs_repair(): void {
		$this->assertTrue( \LuwiPress_Elementor::slug_needs_repair( '44839' ) );
	}

	public function test_real_slug_is_left_alone(): void {
		$this->assertFalse( \LuwiPress_Elementor::slug_needs_repair( 'cookie-policy-de' ) );
		$this->assertFalse( \LuwiPress_Elementor::slug_needs_repair( 'ueber-uns' ) );
	}
}
