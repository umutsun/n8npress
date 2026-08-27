<?php
/**
 * AI Prompt Library
 *
 * System and user prompts for every supported AI workflow.
 * Every method returns ['system' => string, 'user' => string] and is
 * filterable via apply_filters('luwipress_prompt_{workflow}', ...).
 *
 * @package LuwiPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Prompts {

	/**
	 * Product enrichment prompt.
	 *
	 * @param array $product  Product data (name, description, short_description, sku, price, categories, tags, attributes, weight, dimensions).
	 * @param array $options  Options (target_language, currency).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function product_enrichment( array $product, array $options = array() ) {
		$language   = $options['target_language'] ?? get_option( 'luwipress_target_language', 'English' );
		$currency   = $options['currency'] ?? ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
		$categories = is_array( $product['categories'] ?? null ) ? implode( ', ', $product['categories'] ) : ( $product['categories'] ?? 'Uncategorized' );
		$attributes = is_array( $product['attributes'] ?? null ) ? wp_json_encode( $product['attributes'] ) : ( $product['attributes'] ?? '{}' );
		$dimensions = is_array( $product['dimensions'] ?? null ) ? wp_json_encode( $product['dimensions'] ) : ( $product['dimensions'] ?? '{}' );

		// Per-request overrides win over site-wide options. See admin/settings-page.php "Enrichment Prompt & Constraints".
		$custom_system     = (string) ( $options['custom_instructions'] ?? get_option( 'luwipress_enrich_system_prompt', '' ) );
		$target_words      = absint( $options['target_words'] ?? get_option( 'luwipress_enrich_target_words', 0 ) );
		$meta_title_max    = absint( $options['meta_title_max'] ?? get_option( 'luwipress_enrich_meta_title_max', 60 ) );
		$meta_desc_max     = absint( $options['meta_desc_max'] ?? get_option( 'luwipress_enrich_meta_desc_max', 160 ) );

		$default_system = 'You are an e-commerce SEO expert. Generate SEO-optimized content for the given product. Respond ONLY in JSON format, no extra text.';

		if ( '' !== trim( $custom_system ) ) {
			$vars = array(
				'{product_title}'    => $product['name'] ?? '',
				'{category}'         => $categories,
				'{focus_keyword}'    => $options['focus_keyword'] ?? ( $product['name'] ?? '' ),
				'{price}'            => $product['price'] ?? '',
				'{currency}'         => $currency,
				'{site_name}'        => get_bloginfo( 'name' ),
				'{target_language}'  => $language,
			);
			$system = strtr( $custom_system, $vars );
			$system .= "\n\nRespond ONLY with valid JSON matching the schema in the user message. No markdown fences, no prose.";
		} else {
			$system = $default_system;
		}

		$length_hint = $target_words > 0
			? sprintf( 'approximately %d words', $target_words )
			: 'min 200 words';

		$user = sprintf(
			'Generate SEO-optimized content for the following product in %1$s:

Product Name: %2$s
Category: %3$s
Price: %4$s %5$s
SKU: %6$s
Current Description: %7$s
Short Description: %8$s
Attributes: %9$s
Weight: %10$s
Dimensions: %11$s

Respond in JSON:
{
  "description": "Detailed HTML product description (%12$s, use <h2>, <h3>, <p>, <ul>, <strong>, <table> where appropriate)",
  "short_description": "Short description (1-2 sentences with keyword)",
  "meta_title": "SEO title (max %13$d chars)",
  "meta_description": "SEO meta description (max %14$d chars)",
  "faq": [
    { "question": "Q1", "answer": "A1" },
    { "question": "Q2", "answer": "A2" },
    { "question": "Q3", "answer": "A3" },
    { "question": "Q4", "answer": "A4" },
    { "question": "Q5", "answer": "A5" }
  ],
  "alt_text_main": "Main image alt text (product name + keyword)",
  "tags": ["suggested", "tags"]
}',
			$language,
			$product['name'] ?? '',
			$categories,
			$product['price'] ?? '',
			$currency,
			$product['sku'] ?? '',
			$product['description'] ?? 'None',
			$product['short_description'] ?? 'None',
			$attributes,
			$product['weight'] ?? 'Not specified',
			$dimensions,
			$length_hint,
			$meta_title_max,
			$meta_desc_max
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_product_enrichment', $prompt, $product, $options );
	}

	/**
	 * AEO FAQ + HowTo + Speakable prompt.
	 *
	 * @param array $product Product data (name, description, categories, price).
	 * @param array $options Options (target_language).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function aeo_faq( array $product, array $options = array() ) {
		$language   = $options['target_language'] ?? get_option( 'luwipress_target_language', 'English' );
		$categories = is_array( $product['categories'] ?? null ) ? implode( ', ', $product['categories'] ) : ( $product['categories'] ?? '' );

		$system = 'You are an SEO and AEO (Answer Engine Optimization) expert. Generate content optimized for answer engines and voice search. Respond ONLY with valid JSON, no markdown.';

		$user = sprintf(
			'For the following product, generate content in %1$s language:

Product: %2$s
Description: %3$s
Categories: %4$s

Generate the following in valid JSON format:
1. "faqs": Array of 5 FAQ pairs optimized for People Also Ask. Each with "question" and "answer" keys.
2. "howto": If applicable, a HowTo schema with "name", "description", and "steps" array (each step has "name" and "text"). Set to null if not applicable.
3. "speakable": A 2-3 sentence summary optimized for voice search / speakable structured data.

Respond ONLY with valid JSON, no markdown.',
			$language,
			$product['name'] ?? '',
			wp_strip_all_tags( $product['description'] ?? '' ),
			$categories
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_aeo_faq', $prompt, $product, $options );
	}

	/**
	 * AEO HowTo-only prompt.
	 *
	 * @param array $product Product data.
	 * @param array $options Options (target_language).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function aeo_howto( array $product, array $options = array() ) {
		// The AEO generator creates both FAQ and HowTo in a single call.
		return self::aeo_faq( $product, $options );
	}

	/**
	 * Review response prompt.
	 *
	 * @param array  $review     Review data (reviewer, productName, rating, review).
	 * @param string $store_name Store name.
	 * @param string $language   Response language.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function review_response( array $review, $store_name = '', $language = '' ) {
		if ( empty( $store_name ) ) {
			$store_name = get_bloginfo( 'name' );
		}
		if ( empty( $language ) ) {
			$language = get_option( 'luwipress_target_language', 'English' );
		}

		$system = sprintf(
			'You are the customer relations manager for %1$s, an online store. You write replies to customer reviews in %2$s.

Rules:
- Be professional and warm
- Address the customer by name
- Mention the product name in the reply
- For positive reviews (4-5 stars): thank them and invite them to shop again
- For negative reviews (1-3 stars): apologize, offer a solution, and provide contact info
- Keep replies 2-4 sentences
- Do not use emojis
- End with: "%1$s Team"',
			$store_name,
			$language
		);

		$user = sprintf(
			'Write a reply to the following customer review:

Customer: %1$s
Product: %2$s
Rating: %3$s/5
Review: %4$s',
			$review['reviewer'] ?? '',
			$review['productName'] ?? $review['product_name'] ?? '',
			$review['rating'] ?? '',
			wp_strip_all_tags( $review['review'] ?? $review['content'] ?? '' )
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_review_response', $prompt, $review, $store_name, $language );
	}

	/**
	 * Content generation prompt.
	 *
	 * @param array $data Schedule data (topic, language, tone, word_count, keywords, site_name).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function content_generation( array $data ) {
		// Operator-defined system prompt overrides the default — lets sites encode
		// voice, editorial standards, forbidden words, brand context, etc.
		$custom_system = trim( (string) get_option( 'luwipress_content_system_prompt', '' ) );

		$depth = in_array( ( $data['depth'] ?? 'standard' ), array( 'standard', 'deep', 'editorial' ), true )
			? $data['depth']
			: 'standard';

		// Brand voice card — batch-level override falls back to site-level default.
		// Appended to the system prompt so it layers on top of depth rules rather than replacing them.
		$brand_voice = isset( $data['brand_voice'] ) ? trim( (string) $data['brand_voice'] ) : '';
		if ( '' === $brand_voice ) {
			$brand_voice = trim( (string) get_option( 'luwipress_brand_voice_card', '' ) );
		}

		if ( '' !== $custom_system ) {
			// Variable substitution so operators can reference the job's context inside their prompt.
			$system = strtr( $custom_system, array(
				'{topic}'        => (string) ( $data['topic'] ?? '' ),
				'{language}'     => (string) ( $data['language'] ?? 'English' ),
				'{tone}'         => (string) ( $data['tone'] ?? 'professional' ),
				'{word_count}'   => (string) ( $data['word_count'] ?? 1000 ),
				'{keywords}'     => (string) ( $data['keywords'] ?? '' ),
				'{site_name}'    => (string) ( $data['site_name'] ?? get_bloginfo( 'name' ) ),
				'{depth}'        => $depth,
				'{brand_voice}'  => $brand_voice,
			) );
			// If the operator didn't use {brand_voice} but one is set, append it so the voice still lands.
			if ( '' !== $brand_voice && false === strpos( $custom_system, '{brand_voice}' ) ) {
				$system .= self::brand_voice_block( $brand_voice );
			}
		} else {
			$system = self::content_default_system_prompt( $depth );
			if ( '' !== $brand_voice ) {
				$system .= self::brand_voice_block( $brand_voice );
			}
		}

		$user = self::content_user_prompt( $data, $depth );

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_content_generation', $prompt, $data );
	}

	/**
	 * Wrap the operator's brand voice card in a clearly-marked section so the model treats it as a hard constraint layer.
	 */
	private static function brand_voice_block( $brand_voice ) {
		return "\n\n---\nBRAND VOICE CARD (site-specific constraints — always honor on top of the rules above):\n" . $brand_voice . "\n---\n";
	}

	/**
	 * Phase 1 of two-phase editorial generation — produces an outline only.
	 * Humans review/edit the outline before Phase 2 writes the full article.
	 */
	public static function outline_generation( array $data ) {
		$depth = in_array( ( $data['depth'] ?? 'deep' ), array( 'deep', 'editorial' ), true ) ? $data['depth'] : 'deep';

		$section_target = 'editorial' === $depth ? '6-8' : '5-7';
		$faq_target     = 'editorial' === $depth ? '3-5' : '3-5';

		$system = "You are an expert editorial planner. You produce article OUTLINES — not full articles.\n"
			. "The outline is a skeleton a human editor will review, edit, and approve before we write the full piece.\n"
			. "You write the outline in the target language specified. The outline is structural, but every heading and point must be specific and concrete — never generic filler.\n\n"
			. "HARD RULES:\n"
			. " • Every section heading must advance the topic — no overlap, no restating.\n"
			. " • Every bullet point must name a concrete fact, example, name, date, or concept the section will cover. 'Discuss X' is not allowed — say what about X.\n"
			. " • No AI-boilerplate (no 'in today's world', 'as we know', 'in this article we will').\n"
			. " • Keep bullet points short (≤ 18 words). The editor will expand in Phase 2.\n"
			. " • Return ONLY a valid JSON object (no markdown fences, no prose around it).\n";

		$brand_voice = isset( $data['brand_voice'] ) ? trim( (string) $data['brand_voice'] ) : '';
		if ( '' === $brand_voice ) {
			$brand_voice = trim( (string) get_option( 'luwipress_brand_voice_card', '' ) );
		}
		if ( '' !== $brand_voice ) {
			$system .= self::brand_voice_block( $brand_voice );
		}

		$user = sprintf(
			"Plan an article outline for the site '%1\$s'.\n\nTOPIC: %2\$s\nTARGET LANGUAGE: %3\$s\nTONE: %4\$s\nDEPTH PRESET: %5\$s\nSEO KEYWORDS: %6\$s\n\nReturn ONLY this JSON shape:\n{\n  \"title\":            \"specific 45-65 char title\",\n  \"hook\":             \"1-2 sentence opening idea — anecdote, contrast, vivid image, or surprising fact (NOT a summary of the article)\",\n  \"sections\":         [ { \"heading\": \"H2 heading\", \"points\": [\"concrete point\", \"concrete point\", \"concrete point\"] } ],\n  \"faq\":              [\"Question readers actually search?\"],\n  \"closing_approach\": \"short description of how to close (reflective line, memorable image, CTA, etc) — not a summary\"\n}\n\nTARGETS:\n • sections: %7\$s H2 sections, each with 3-5 concrete points\n • faq: %8\$s questions (only include if they add value)",
			$data['site_name']  ?? get_bloginfo( 'name' ),
			$data['topic']      ?? '',
			$data['language']   ?? 'English',
			$data['tone']       ?? 'informative',
			$depth,
			$data['keywords']   ?? '',
			$section_target,
			$faq_target
		);

		$prompt = array( 'system' => $system, 'user' => $user );
		return apply_filters( 'luwipress_prompt_outline_generation', $prompt, $data );
	}

	/**
	 * Phase 2 — full article written STRICTLY from an approved outline.
	 * Expects `outline` key in $data (array shape matching outline_generation output).
	 */
	public static function content_from_outline( array $data ) {
		$depth   = in_array( ( $data['depth'] ?? 'deep' ), array( 'standard', 'deep', 'editorial' ), true ) ? $data['depth'] : 'deep';
		$outline = is_array( $data['outline'] ?? null ) ? $data['outline'] : array();

		// Reuse the full article system prompt + layer outline adherence on top.
		$system = self::content_default_system_prompt( $depth );

		$brand_voice = isset( $data['brand_voice'] ) ? trim( (string) $data['brand_voice'] ) : '';
		if ( '' === $brand_voice ) {
			$brand_voice = trim( (string) get_option( 'luwipress_brand_voice_card', '' ) );
		}
		if ( '' !== $brand_voice ) {
			$system .= self::brand_voice_block( $brand_voice );
		}

		$system .= "\n\n---\nAPPROVED OUTLINE ADHERENCE:\n"
			. " • An editor has already approved the outline below. Follow it exactly.\n"
			. " • Do not add new H2 sections. Do not skip H2 sections. Do not rename H2 headings.\n"
			. " • Expand every bullet point into substantial prose — one short paragraph each minimum.\n"
			. " • The hook opening must match the spirit of the outline's hook (same angle, full sentence execution).\n"
			. " • FAQ items must cover every question listed, in order.\n"
			. " • Close using the closing_approach described.\n"
			. "---\n";

		$outline_text = self::format_outline_for_prompt( $outline );

		$user = sprintf(
			"Write the full article for the site '%1\$s' following the approved outline below.\n\nTOPIC: %2\$s\nTARGET LANGUAGE: %3\$s\nTONE: %4\$s\nTARGET WORD COUNT: %5\$s\nSEO KEYWORDS: %6\$s\nDEPTH PRESET: %7\$s\n\nAPPROVED OUTLINE:\n%8\$s\n\nReturn ONLY a JSON object:\n{\n  \"title\":           \"use the outline title unless it's clearly poor — 45-65 chars\",\n  \"content\":         \"Full HTML article — <h2>/<h3>/<p>/<ul>/<ol>/<blockquote>/<table>. Internal link placeholders inline as [INTERNAL_LINK: anchor].\",\n  \"excerpt\":         \"2-3 sentence summary, max 160 chars\",\n  \"meta_title\":      \"SEO meta title, max 60 chars\",\n  \"meta_description\": \"SEO meta description, max 155 chars, compelling CTA\",\n  \"tags\":            [\"5-8 specific, searchable tags\"]\n}",
			$data['site_name']  ?? get_bloginfo( 'name' ),
			$data['topic']      ?? '',
			$data['language']   ?? 'English',
			$data['tone']       ?? 'informative',
			$data['word_count'] ?? 2000,
			$data['keywords']   ?? '',
			$depth,
			$outline_text
		);

		$prompt = array( 'system' => $system, 'user' => $user );
		return apply_filters( 'luwipress_prompt_content_from_outline', $prompt, $data );
	}

	/**
	 * Render an outline array as a readable block for prompt injection.
	 */
	private static function format_outline_for_prompt( array $outline ) {
		$lines = array();
		$lines[] = 'TITLE: ' . ( $outline['title'] ?? '' );
		$lines[] = 'HOOK: ' . ( $outline['hook'] ?? '' );
		$lines[] = '';
		$lines[] = 'SECTIONS:';
		if ( ! empty( $outline['sections'] ) && is_array( $outline['sections'] ) ) {
			foreach ( $outline['sections'] as $idx => $section ) {
				$heading = $section['heading'] ?? '';
				$lines[] = sprintf( '%d. %s', $idx + 1, $heading );
				$points = $section['points'] ?? array();
				if ( is_array( $points ) ) {
					foreach ( $points as $point ) {
						$lines[] = '   - ' . $point;
					}
				}
			}
		}
		if ( ! empty( $outline['faq'] ) && is_array( $outline['faq'] ) ) {
			$lines[] = '';
			$lines[] = 'FAQ:';
			foreach ( $outline['faq'] as $q ) {
				$lines[] = '  • ' . $q;
			}
		}
		if ( ! empty( $outline['closing_approach'] ) ) {
			$lines[] = '';
			$lines[] = 'CLOSING: ' . $outline['closing_approach'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Topic brainstorm — produces a list of specific, publishable article titles from a theme.
	 */
	public static function topic_brainstorm( array $data ) {
		$theme = (string) ( $data['theme'] ?? '' );
		$count = max( 1, min( 20, (int) ( $data['count'] ?? 10 ) ) );
		$style = sanitize_text_field( (string) ( $data['style_hint'] ?? '' ) );
		$lang  = (string) ( $data['language'] ?? 'English' );

		$system = "You generate article title ideas for a working content queue — NOT a generic brainstorm list.\n"
			. "Every title must be specific, concrete, publishable as-is, and searchable. Reject generic templates.\n\n"
			. "HARD RULES:\n"
			. " • Title must name a specific thing: a person, place, date, number, concept, or object. Not 'exploring music' but 'How Aşık Veysel's tuning shaped modern bağlama'.\n"
			. " • No title is allowed to start with: 'The Ultimate Guide', 'Everything You Need', 'A Beginner's Guide' (unless truly beginner-targeted).\n"
			. " • Vary angles: history, craft, culture, technique, comparison, personal voice, how-to.\n"
			. " • No duplicates. No two titles about the same angle of the same subject.\n"
			. " • Write titles in the target language.\n"
			. " • Return ONLY valid JSON (no markdown fences, no prose around it).\n";

		$brand_voice = isset( $data['brand_voice'] ) ? trim( (string) $data['brand_voice'] ) : '';
		if ( '' === $brand_voice ) {
			$brand_voice = trim( (string) get_option( 'luwipress_brand_voice_card', '' ) );
		}
		if ( '' !== $brand_voice ) {
			$system .= self::brand_voice_block( $brand_voice );
		}

		$existing = $data['existing_titles'] ?? array();
		$existing_block = '';
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$existing_block = "\n\nAVOID DUPLICATES — these titles already exist on the site, do NOT propose variations of them:\n"
				. '• ' . implode( "\n• ", array_slice( $existing, 0, 50 ) );
		}

		$user = sprintf(
			"Theme: %1\$s\nTarget language: %2\$s\nStyle hint (optional): %3\$s\nCount: %4\$d\n%5\$s\n\nReturn ONLY this JSON:\n{\n  \"topics\": [\n    { \"title\": \"...\", \"angle\": \"1-line reason this angle is publishable\", \"depth\": \"standard|deep|editorial\" }\n  ]\n}",
			$theme,
			$lang,
			$style !== '' ? $style : 'none',
			$count,
			$existing_block
		);

		$prompt = array( 'system' => $system, 'user' => $user );
		return apply_filters( 'luwipress_prompt_topic_brainstorm', $prompt, $data );
	}

	/**
	 * Default system prompt by depth preset. Operators can override the whole
	 * thing via `luwipress_content_system_prompt` option (admin Settings).
	 *
	 * Depth presets:
	 *  - standard:  balanced SEO article (800-1500 words, clear structure)
	 *  - deep:      long-form explainer with research framing, examples, citations,
	 *               counter-arguments, practical takeaways, FAQ (1500-3000 words)
	 *  - editorial: essay-style long-form with strong voice, cultural/historical
	 *               context, narrative arc, original perspective, quote-worthy
	 *               sentences (2000-3500+ words)
	 */
	private static function content_default_system_prompt( $depth ) {
		$base = "You are an expert editorial writer and SEO strategist for the site you are writing for.\n"
			. "You produce content that reads like it was researched and written by a subject-matter expert — not templated, not padded, not AI-boilerplate.\n"
			. "You write in the target language specified in the user prompt. Match the tone requested, but never sacrifice accuracy or depth for it.\n\n"
			. "HARD RULES:\n"
			. " • No filler sentences ('In this article we will discuss…', 'As we all know…', 'In today's fast-paced world…'). Start with the most interesting, specific sentence the topic allows.\n"
			. " • No hedging fluff ('might be considered to potentially be…'). Make clear claims; qualify only when genuinely uncertain.\n"
			. " • Every H2 section must advance the topic — no repetition, no restating the intro.\n"
			. " • Use concrete examples, specific names, dates, numbers, or quotes where relevant. Vague > specific is a failure.\n"
			. " • Mark internal linking opportunities inline as [INTERNAL_LINK: anchor text] — place them naturally where a reader would click.\n"
			. " • Return ONLY valid JSON (no markdown fences, no prose around it).\n";

		if ( 'deep' === $depth ) {
			return $base . "\nDEPTH: DEEP EXPLAINER\n"
				. " • Target 1500-3000 words.\n"
				. " • Structure: opening hook → clear thesis → 4-7 H2 sections covering distinct facets → 'Key takeaways' bullet list → 'Frequently asked questions' H2 with 3-5 Q&A pairs.\n"
				. " • Include at least one comparison table or numbered list where the topic warrants it.\n"
				. " • Cite real sources, studies, books, or people by name (verify what you know; don't invent references).\n"
				. " • End with a clear call-to-action suited to the site's context.\n";
		}

		if ( 'editorial' === $depth ) {
			return $base . "\nDEPTH: EDITORIAL ESSAY\n"
				. " • Target 2000-3500+ words.\n"
				. " • Structure: strong narrative hook (anecdote, contrast, question, vivid image) → thesis → 5-8 H2 sections that build an argument or story arc → reflective conclusion that leaves a lasting impression.\n"
				. " • Write with a distinctive voice — not neutral encyclopedia tone. Opinions and interpretations welcome, clearly framed as such.\n"
				. " • Weave in cultural, historical, or personal context. Draw unexpected connections between the topic and adjacent ideas.\n"
				. " • Include at least 2-3 quote-worthy sentences a reader would highlight or share.\n"
				. " • Close with a memorable final line — not a summary.\n"
				. " • The article should feel like it belongs in a curated publication, not a content mill.\n";
		}

		// standard
		return $base . "\nDEPTH: STANDARD\n"
			. " • Target the requested word count (typical 800-1500 words).\n"
			. " • Structure: clear introduction (2-3 sentences) → 3-5 H2 sections → brief conclusion with a call-to-action.\n"
			. " • Balance SEO structure with genuine usefulness to the reader.\n";
	}

	/**
	 * User-message body — carries the concrete job data.
	 */
	private static function content_user_prompt( array $data, $depth ) {
		return sprintf(
			"Write an article for the site '%6\$s'.\n\nTOPIC: %1\$s\nTARGET LANGUAGE: %2\$s\nTONE: %3\$s\nTARGET WORD COUNT: %4\$s\nSEO KEYWORDS: %5\$s\nDEPTH PRESET: %7\$s\n\nReturn ONLY a JSON object with this shape (no markdown fences, no prose around it):\n{\n  \"title\":           \"SEO-optimized title, 45-65 chars\",\n  \"content\":         \"Full HTML article — <h2>/<h3>/<p>/<ul>/<ol>/<blockquote>/<table> as appropriate. Internal link placeholders inline as [INTERNAL_LINK: anchor].\",\n  \"excerpt\":         \"2-3 sentence summary, max 160 chars\",\n  \"meta_title\":      \"SEO meta title, max 60 chars\",\n  \"meta_description\": \"SEO meta description, max 155 chars, compelling CTA\",\n  \"tags\":            [\"5-8 specific, searchable tags (not generic like 'music')\"]\n}",
			$data['topic']      ?? '',
			$data['language']   ?? 'English',
			$data['tone']       ?? 'professional',
			$data['word_count'] ?? 1000,
			$data['keywords']   ?? '',
			$data['site_name']  ?? get_bloginfo( 'name' ),
			$depth
		);
	}

	/**
	 * Product translation prompt.
	 *
	 * @param array  $content     Content to translate (name, description, short_description, meta_title, meta_description, focus_keyword).
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @param int    $product_id  Product ID.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function translation( array $content, $source_lang, $target_lang, $product_id = 0 ) {
		// FAQ round-trip: enumerate source Q&A entries so the AI can translate each
		// in place, then emit a matching-length JSON array for the writer to persist
		// back to the target post's _luwipress_faq meta. See ISSUE-032.
		$faq        = is_array( $content['faq'] ?? null ) ? $content['faq'] : array();
		$faq_count  = 0;
		$faq_lines  = array();
		foreach ( $faq as $i => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$q = isset( $entry['question'] ) ? (string) $entry['question'] : '';
			$a = isset( $entry['answer'] )   ? (string) $entry['answer']   : '';
			if ( '' === $q && '' === $a ) {
				continue;
			}
			$faq_lines[] = sprintf( "  [%d] Q: %s\n      A: %s", $i, $q, $a );
			$faq_count++;
		}
		$faq_input  = $faq_lines ? "FAQ entries (translate each Q AND A):\n" . implode( "\n", $faq_lines ) : 'FAQ entries: none';
		$faq_schema = '';
		if ( $faq_count > 0 ) {
			$faq_schema = ', "faq": [' . rtrim( str_repeat( '{"question": "", "answer": ""}, ', $faq_count ), ', ' ) . ']';
		} else {
			$faq_schema = ', "faq": []';
		}

		$system = sprintf(
			'You are an expert SEO-aware translator for e-commerce. Translate the following product from %1$s to %2$s.

RULES:
- Preserve brand names, SKUs, and proper nouns as-is.
- Meta title <60 chars. Meta description <160 chars. Use natural %2$s. Preserve HTML markup.
- Focus Keyword MUST be fully translated to %2$s. No source-language tokens may remain (e.g. "silent" → "silencieux"). If the source keyword is multi-word, translate every word.
- If FAQ entries are provided, translate BOTH the question and the answer of EACH entry. Keep the same count and order; never drop, merge, or invent entries.',
			$source_lang,
			$target_lang
		);

		// Brand/product names the operator marked as untranslatable.
		$system .= LuwiPress_Translation::glossary_prompt_rule();

		$user = sprintf(
			'Product ID: %1$d
Target Language: %2$s
Title: %3$s
Description: %4$s
Short Description: %5$s
Meta Title: %6$s
Meta Description: %7$s
Focus Keyword: %8$s
%9$s

Respond ONLY with valid JSON:
{"product_id": %1$d, "target_language": "%2$s", "title": "", "description": "", "short_description": "", "meta_title": "", "meta_description": "", "focus_keyword": "", "slug": ""%10$s}',
			$product_id,
			$target_lang,
			$content['name'] ?? $content['title'] ?? '',
			$content['description'] ?? '',
			$content['short_description'] ?? '',
			$content['meta_title'] ?? '',
			$content['meta_description'] ?? '',
			$content['focus_keyword'] ?? '',
			$faq_input,
			$faq_schema
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_translation', $prompt, $content, $source_lang, $target_lang );
	}

	/**
	 * Elementor HTML chunk translation prompt.
	 * Returns plain translated HTML (not JSON) to avoid parse failures on long content.
	 *
	 * @param string $html_chunk   HTML content to translate.
	 * @param string $source_lang  Source language name (e.g. 'Turkish').
	 * @param string $target_lang  Target language name (e.g. 'French').
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function elementor_html_translation( $html_chunk, $source_lang, $target_lang ) {
		$system = sprintf(
			'You are an expert translator. Translate the following HTML content from %1$s to %2$s.

RULES:
- Preserve ALL HTML tags, attributes, classes, and structure exactly as-is.
- Only translate visible text content between tags.
- Keep brand names, product names, and proper nouns as-is.
- Do NOT wrap the output in JSON, code fences, or any wrapper — return ONLY the translated HTML.
- Do NOT add any explanation or commentary.',
			$source_lang,
			$target_lang
		);

		$system .= LuwiPress_Translation::glossary_prompt_rule();

		$user = $html_chunk;

		return array( 'system' => $system, 'user' => $user );
	}

	/**
	 * Taxonomy translation prompt.
	 *
	 * @param array  $terms       Array of terms: [['term_id' => int, 'name' => string, 'slug' => string], ...].
	 * @param string $taxonomy    Taxonomy type (product_cat, product_tag).
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function taxonomy_translation( array $terms, $taxonomy, $source_lang, $target_lang ) {
		$tax_label = ( 'product_cat' === $taxonomy ) ? 'product category' : 'product tag';

		$system = sprintf(
			'You are an SEO-aware translator for e-commerce taxonomy terms. Translate %1$s names from %2$s to %3$s. Create SEO-friendly slugs (lowercase, hyphens, no special chars). Keep brand names as-is. Return ONLY valid JSON array.',
			$tax_label,
			$source_lang,
			$target_lang
		);

		$terms_list = '';
		foreach ( $terms as $t ) {
			$terms_list .= sprintf(
				"- term_id: %d, name: \"%s\", slug: \"%s\"\n",
				$t['term_id'] ?? 0,
				$t['name'] ?? '',
				$t['slug'] ?? ''
			);
		}

		$user = sprintf(
			'Translate the following %1$s names from %2$s to %3$s.

Terms:
%4$s
Respond with:
[{"term_id": 123, "name": "translated name", "slug": "translated-slug", "language": "%3$s"}]',
			$tax_label,
			$source_lang,
			$target_lang,
			$terms_list
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_taxonomy_translation', $prompt, $terms, $taxonomy, $source_lang, $target_lang );
	}

	/**
	 * Internal linking prompt.
	 *
	 * @param array  $post_data Post data (title, post_type).
	 * @param array  $markers   Array of [INTERNAL_LINK: topic] marker strings.
	 * @param string $store_name Store name.
	 * @param string $site_url   Site URL.
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function internal_linking( array $post_data, array $markers, $store_name = '', $site_url = '' ) {
		if ( empty( $store_name ) ) {
			$store_name = get_bloginfo( 'name' );
		}
		if ( empty( $site_url ) ) {
			$site_url = get_site_url();
		}

		$system = sprintf(
			'You are an internal linking expert for %1$s (%2$s). For each topic marker, suggest the best URL and anchor text from the store\'s existing pages.

Output a JSON array of objects: [{"topic": "original topic", "url": "best matching URL", "anchor": "optimized anchor text"}]

Rules:
- Use existing product, category, or blog post URLs from the store
- Anchor text should be natural and SEO-friendly
- If you cannot find a match, set url to empty string
- Prefer product pages over category pages
- Keep anchor text under 50 characters',
			$store_name,
			$site_url
		);

		$markers_list = '';
		foreach ( $markers as $i => $marker ) {
			$markers_list .= sprintf( "%d. [INTERNAL_LINK: %s]\n", $i + 1, $marker );
		}

		$user = sprintf(
			'Post: "%1$s" (%2$s)

Resolve these internal link markers:
%3$s
Store URL: %4$s',
			$post_data['title'] ?? '',
			$post_data['post_type'] ?? 'post',
			$markers_list,
			$site_url
		);

		$prompt = array( 'system' => $system, 'user' => $user );

		return apply_filters( 'luwipress_prompt_internal_linking', $prompt, $post_data, $markers );
	}

	/**
	 * Open Claw chat prompt.
	 *
	 * @param string $message      User message.
	 * @param array  $site_context Site context (site_name, site_url, products, posts, seo_plugin, translation_plugin, active_languages, available_actions).
	 * @return array ['system' => string, 'user' => string]
	 */
	public static function open_claw_chat( $message, array $site_context = array() ) {
		$actions_list = '';
		if ( ! empty( $site_context['available_actions'] ) && is_array( $site_context['available_actions'] ) ) {
			foreach ( $site_context['available_actions'] as $key => $desc ) {
				$actions_list .= sprintf( "- %s: %s\n", $key, $desc );
			}
		}

		$system = sprintf(
			'You are Open Claw, an AI assistant for managing WordPress + WooCommerce stores.

Store: %1$s (%2$s)
Products: %3$s | Posts: %4$s
SEO Plugin: %5$s
Translation: %6$s
Languages: %7$s

Available actions:
%8$s
Rules:
- Be concise and helpful
- Use markdown for formatting
- For actions, end with JSON: {"action": "type", "params": {}, "confirm": true}
- Include specific numbers when possible',
			$site_context['site_name'] ?? get_bloginfo( 'name' ),
			$site_context['site_url'] ?? get_site_url(),
			$site_context['products'] ?? 0,
			$site_context['posts'] ?? 0,
			$site_context['seo_plugin'] ?? 'none',
			$site_context['translation_plugin'] ?? 'none',
			is_array( $site_context['active_languages'] ?? null ) ? implode( ', ', $site_context['active_languages'] ) : 'default',
			$actions_list
		);

		$prompt = array( 'system' => $system, 'user' => $message );

		return apply_filters( 'luwipress_prompt_open_claw_chat', $prompt, $message, $site_context );
	}

	/**
	 * Image generation prompt for DALL-E.
	 *
	 * @param string $title   Article or product title.
	 * @param string $context Additional context (keywords, description).
	 * @return string DALL-E prompt.
	 */
	public static function image_generation( $title, $context = '' ) {
		$prompt = sprintf(
			'Create a professional, high-quality featured image for a blog article titled "%s". %sThe image should be clean, modern, and suitable for a professional e-commerce website. No text in the image.',
			$title,
			! empty( $context ) ? "Context: {$context}. " : ''
		);

		return apply_filters( 'luwipress_prompt_image_generation', $prompt, $title, $context );
	}

	// ────────────────────────────────────────────────────────────────────
	// CRM Campaign prompts (win-back / onboarding / vip-perk / loyalty)
	// ────────────────────────────────────────────────────────────────────
	//
	// Each segment has its own voice + conversion goal. We do NOT share a
	// generic "marketing email" prompt because the rhetoric is wildly
	// different: a one-time buyer needs a no-shame nudge ("here's what's
	// new"); an at-risk loyal customer needs acknowledgement ("we miss
	// you"); a VIP needs scarcity + privilege ("48-hour pre-access").
	//
	// All four return `['system' => …, 'user' => …]` and ask the model for
	// strict JSON: `{ subject, preheader, body_html, cta_label }`. Campaign
	// dispatcher reads that envelope; non-JSON responses get a generic
	// fallback so a malformed AI reply never silently sends "undefined".

	/**
	 * Win-back campaign for one-time buyers (highest-ROI segment).
	 *
	 * @param array $ctx { store_name, customer_first_name?, last_purchase?, language, coupon_pct?, coupon_days? }
	 * @return array
	 */
	public static function crm_winback_one_time( array $ctx = array() ) {
		$language = $ctx['language'] ?? 'English';
		$store    = $ctx['store_name'] ?? get_bloginfo( 'name' );
		$pct      = absint( $ctx['coupon_pct'] ?? 15 );
		$days     = absint( $ctx['coupon_days'] ?? 30 );
		$first    = (string) ( $ctx['customer_first_name'] ?? '' );

		$system = 'You are a thoughtful e-commerce copywriter. Tone: warm, no shame, no FOMO bullying, no exclamation-mark spam. Goal: invite a one-time buyer back with a single soft reason + a single concrete offer. NEVER use phrases like "we miss you" verbatim, NEVER guilt-trip, NEVER write more than 90 words in body. Respond ONLY in JSON: { "subject": string (<= 55 chars), "preheader": string (<= 90 chars), "body_html": string (HTML allowed: <p>, <strong>, <a>), "cta_label": string (<= 22 chars) }.';

		$user = sprintf(
			"Write a win-back email for a one-time buyer of %s.\n\nLanguage: %s.\nCustomer first name (use only if non-empty): %s\n\nOffer: %d%% off, code provided separately, expires in %d days.\n\nBody must include: (1) a single sentence acknowledging time has passed, (2) one specific reason to come back NOW (new arrivals OR a category they likely care about — keep it abstract), (3) the offer one-liner, (4) CTA paragraph.\n\nNo emojis. Plain professional tone.",
			$store,
			$language,
			'' !== $first ? $first : '(unknown — use generic greeting like "Hi there,")',
			$pct,
			$days
		);

		return apply_filters( 'luwipress_prompt_crm_winback_one_time', array( 'system' => $system, 'user' => $user ), $ctx );
	}

	/**
	 * At-risk loyalty perk — for customers whose last order is ageing but
	 * who have multiple historical purchases. Tone: appreciative, not desperate.
	 */
	public static function crm_at_risk_perk( array $ctx = array() ) {
		$language = $ctx['language'] ?? 'English';
		$store    = $ctx['store_name'] ?? get_bloginfo( 'name' );
		$pct      = absint( $ctx['coupon_pct'] ?? 20 );
		$days     = absint( $ctx['coupon_days'] ?? 14 );
		$first    = (string) ( $ctx['customer_first_name'] ?? '' );

		$system = 'You are a retention copywriter for a high-trust independent store. Tone: appreciative, slightly intimate (they bought from you multiple times before), zero gimmicks. Goal: re-engage a loyal customer whose last order is ageing, before they drift permanently. NEVER use clichés ("long time no see"), NEVER mention churn or risk language. Respond ONLY in JSON: { "subject": string (<= 55 chars), "preheader": string (<= 90 chars), "body_html": string (HTML allowed), "cta_label": string (<= 22 chars) }.';

		$user = sprintf(
			"Write an at-risk loyalty perk email for a previously regular customer of %s.\n\nLanguage: %s.\nCustomer first name (use only if non-empty): %s\n\nOffer: %d%% off thank-you perk, code provided separately, expires in %d days.\n\nBody must include: (1) a one-line thank-you anchor that acknowledges their prior support without being saccharine, (2) one sentence on what's recent / interesting at the store, (3) the perk one-liner framed as a thank-you not a discount push, (4) CTA paragraph.\n\nUnder 90 words. No emojis.",
			$store,
			$language,
			'' !== $first ? $first : '(unknown — use respectful generic greeting)',
			$pct,
			$days
		);

		return apply_filters( 'luwipress_prompt_crm_at_risk_perk', array( 'system' => $system, 'user' => $user ), $ctx );
	}

	/**
	 * New customer onboarding — first 30 days, builds the second purchase.
	 */
	public static function crm_new_onboarding( array $ctx = array() ) {
		$language = $ctx['language'] ?? 'English';
		$store    = $ctx['store_name'] ?? get_bloginfo( 'name' );
		$pct      = absint( $ctx['coupon_pct'] ?? 10 );
		$days     = absint( $ctx['coupon_days'] ?? 30 );
		$first    = (string) ( $ctx['customer_first_name'] ?? '' );

		$system = 'You are an onboarding copywriter for a craft / specialty e-commerce store. Tone: welcoming, useful, low-pressure. Goal: orient a brand-new customer (just made their first purchase) and seed the second one without aggression. NEVER write a hard sales push, NEVER use "limited time" pressure language. Respond ONLY in JSON: { "subject": string (<= 55 chars), "preheader": string (<= 90 chars), "body_html": string (HTML allowed), "cta_label": string (<= 22 chars) }.';

		$user = sprintf(
			"Write an onboarding email for a brand-new customer of %s (within their first 30 days).\n\nLanguage: %s.\nCustomer first name (use only if non-empty): %s\n\nOffer: %d%% off welcome perk for their second purchase, code provided separately, expires in %d days.\n\nBody must include: (1) a real welcome (no robot voice), (2) one sentence on what makes this store worth exploring (quality / craft / curation — keep it concrete), (3) one suggestion of what to look at next (categories, recently added — abstract is fine), (4) the perk + CTA.\n\nUnder 100 words. No emojis.",
			$store,
			$language,
			'' !== $first ? $first : '(unknown — use friendly generic greeting)',
			$pct,
			$days
		);

		return apply_filters( 'luwipress_prompt_crm_new_onboarding', array( 'system' => $system, 'user' => $user ), $ctx );
	}

	/**
	 * VIP perk announcement — top spenders, scarcity + privilege not discount.
	 */
	public static function crm_vip_perk( array $ctx = array() ) {
		$language = $ctx['language'] ?? 'English';
		$store    = $ctx['store_name'] ?? get_bloginfo( 'name' );
		$pct      = absint( $ctx['coupon_pct'] ?? 0 ); // VIPs often get free shipping, not %.
		$days     = absint( $ctx['coupon_days'] ?? 90 );
		$free_ship = ! empty( $ctx['free_shipping'] );
		$first    = (string) ( $ctx['customer_first_name'] ?? '' );

		$perk_line = $free_ship && 0 === $pct
			? 'Free shipping (no minimum) for the next ' . $days . ' days.'
			: ( $pct > 0 ? $pct . '% off + free shipping (no minimum), expires in ' . $days . ' days.' : 'A small thank-you perk — details inside.' );

		$system = 'You are writing to the top 5% of customers by lifetime spend. Tone: composed, low-key, treat them like insiders not marks. Goal: reinforce belonging + offer a quiet privilege (not a discount push). NEVER write "VIP" or "exclusive" in the subject line. NEVER use scarcity manipulation. Respond ONLY in JSON: { "subject": string (<= 55 chars), "preheader": string (<= 90 chars), "body_html": string (HTML allowed), "cta_label": string (<= 22 chars) }.';

		$user = sprintf(
			"Write a top-customer thank-you perk email for %s.\n\nLanguage: %s.\nCustomer first name (use only if non-empty): %s\n\nPerk: %s\n\nBody must include: (1) a single sentence framing them as someone the store knows + values (without naming the segment), (2) one quiet acknowledgement of their share of the store's growth, (3) the perk in plain English, (4) low-key CTA.\n\nUnder 80 words. No emojis. No hype.",
			$store,
			$language,
			'' !== $first ? $first : '(unknown — use respectful generic greeting)',
			$perk_line
		);

		return apply_filters( 'luwipress_prompt_crm_vip_perk', array( 'system' => $system, 'user' => $user ), $ctx );
	}
}
