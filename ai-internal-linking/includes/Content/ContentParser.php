<?php
/**
 * Universal READ layer: extract plain text and existing links from a post,
 * regardless of how the body is stored. Uses raw parsing (never render APIs),
 * so it is safe in cron/background contexts.
 *
 * Write-back (safe link insertion per builder) is a separate concern handled by
 * the apply feature in Phase 0b.
 *
 * @package AILinking
 */

namespace AILinking\Content;

use AILinking\Detectors\BuilderDetector;

defined( 'ABSPATH' ) || exit;

class ContentParser {

	const MAX_TEXT = 120000;

	/**
	 * Parse a post into normalised text + discovered links.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array{text:string,links:array<int,array{url:string,anchor:string}>,system:string}
	 */
	public static function parse( \WP_Post $post ) {
		$system = BuilderDetector::detect( $post );
		$html   = '';

		switch ( $system ) {
			case BuilderDetector::GUTENBERG:
				$html = self::gutenberg_html( $post->post_content );
				break;
			case BuilderDetector::ELEMENTOR:
				$html = self::elementor_html( $post->ID );
				break;
			case BuilderDetector::BEAVER:
				$html = self::beaver_html( $post->ID );
				break;
			case BuilderDetector::DIVI:
			case BuilderDetector::WPBAKERY:
				$html = self::shortcode_html( $post->post_content );
				break;
			case BuilderDetector::CLASSIC:
			default:
				$html = $post->post_content;
				break;
		}

		// ACF text fields are orthogonal — append regardless of primary system.
		if ( BuilderDetector::has_acf( $post->ID ) ) {
			$html .= ' ' . self::acf_html( $post->ID );
		}

		$links = self::extract_links( $html );

		// Build the text corpus from the BODY only. The post title is the page's
		// H1, so it is intentionally excluded — anchors must never come from a
		// heading/title (headings inside the body are stripped in html_to_text).
		$text = self::html_to_text( $html );
		if ( strlen( $text ) > self::MAX_TEXT ) {
			$text = substr( $text, 0, self::MAX_TEXT );
		}

		return array(
			'text'   => $text,
			'links'  => $links,
			'system' => $system,
		);
	}

	/**
	 * Recursively collect innerHTML from Gutenberg blocks.
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	private static function gutenberg_html( $content ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		return self::walk_blocks( $blocks );
	}

	/**
	 * @param array $blocks Parsed blocks.
	 * @return string
	 */
	private static function walk_blocks( $blocks ) {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( isset( $block['innerHTML'] ) ) {
				$html .= ' ' . $block['innerHTML'];
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$html .= ' ' . self::walk_blocks( $block['innerBlocks'] );
			}
		}
		return $html;
	}

	/**
	 * Extract text-bearing HTML from Elementor's _elementor_data JSON tree.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function elementor_html( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return '';
		}
		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = $raw;
		}
		if ( ! is_array( $data ) ) {
			return '';
		}
		$collected = array();
		self::walk_elementor( $data, $collected );
		return implode( ' ', $collected );
	}

	/**
	 * @param array $elements   Elementor element tree.
	 * @param array $collected  Accumulator (by reference).
	 */
	private static function walk_elementor( $elements, &$collected ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $el['settings'] as $key => $value ) {
					if ( is_string( $value ) && '' !== $value && preg_match( '/(editor|text|title|description|content|html|caption|heading)/i', $key ) ) {
						$collected[] = $value;
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_elementor( $el['elements'], $collected );
			}
		}
	}

	/**
	 * Extract text from Beaver Builder's serialized module data.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function beaver_html( $post_id ) {
		$data = get_post_meta( $post_id, '_fl_builder_data', true );
		$data = maybe_unserialize( $data );
		if ( empty( $data ) ) {
			return '';
		}
		$collected = array();
		self::walk_strings( $data, $collected );
		return implode( ' ', $collected );
	}

	/**
	 * Extract text from ACF text-bearing fields.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function acf_html( $post_id ) {
		if ( ! function_exists( 'get_fields' ) ) {
			return '';
		}
		$fields = get_fields( $post_id );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}
		$collected = array();
		self::walk_strings( $fields, $collected );
		return implode( ' ', $collected );
	}

	/**
	 * Recursively gather prose-like strings from an arbitrary array/object tree.
	 *
	 * @param mixed $node      Node.
	 * @param array $collected Accumulator (by reference).
	 */
	private static function walk_strings( $node, &$collected ) {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $value ) {
				self::walk_strings( $value, $collected );
			}
			return;
		}
		if ( is_string( $node ) && strlen( $node ) > 2 && preg_match( '/\p{L}/u', $node ) ) {
			// Skip values that are obviously not prose (pure CSS/URLs/ids).
			if ( ! preg_match( '#^https?://#', $node ) && false === strpos( $node, '{' ) ) {
				$collected[] = $node;
			}
		}
	}

	/**
	 * Strip shortcode tags (keep inner text) for Divi/WPBakery content.
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	private static function shortcode_html( $content ) {
		// Remove the bracketed shortcode tags themselves, preserving inner content.
		return preg_replace( '/\[\/?[^\]]+\]/', ' ', $content );
	}

	/**
	 * Convert HTML to a plain-text corpus.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		// Drop heading text (H1-H6) entirely so anchors are never drawn from headings.
		$html = preg_replace( '#<h[1-6][^>]*>.*?</h[1-6]>#is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Extract anchor links from an HTML string (UTF-8 safe).
	 *
	 * @param string $html HTML.
	 * @return array<int,array{url:string,anchor:string}>
	 */
	private static function extract_links( $html ) {
		$links = array();
		if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $links;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		// Force UTF-8 so multilingual content is not corrupted.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript:' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
				continue;
			}
			$links[] = array(
				'url'    => $href,
				'anchor' => trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ),
			);
		}

		return $links;
	}
}
