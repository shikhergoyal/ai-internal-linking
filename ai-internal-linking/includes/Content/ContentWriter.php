<?php
/**
 * Per-system safe link writer. Phase 0b implements the two systems whose
 * canonical content is plain post_content HTML: Gutenberg and Classic. Other
 * systems are suggest-only and never reach this writer.
 *
 * Every write is validated: exactly one eligible occurrence, and a visible-text
 * integrity check (the rewrite must change nothing but wrap the anchor).
 *
 * @package AILinking
 */

namespace AILinking\Content;

use AILinking\Detectors\BuilderDetector;

defined( 'ABSPATH' ) || exit;

class ContentWriter {

	/**
	 * Allowlist of core text blocks we will auto-write into. Anything else
	 * (custom blocks, HTML/code/shortcode blocks, classic-in-block freeform) is
	 * left untouched and falls back to suggest-only.
	 */
	// Note: core/heading is intentionally excluded — links must never go in headings.
	const ALLOWED_BLOCKS = array( 'core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote', 'core/verse' );

	/**
	 * Produce the rewritten field value for a post.
	 *
	 * @param \WP_Post $post    Source post.
	 * @param string   $system  Content system.
	 * @param string   $anchor  Anchor phrase (verbatim).
	 * @param string   $href    Target URL.
	 * @param string   $data_id Provenance id.
	 * @return array{ok:bool,value_before?:string,value_after?:string,storage_target?:string,meta_key?:string,reason?:string}
	 */
	public static function write( \WP_Post $post, $system, $anchor, $href, $data_id ) {
		if ( BuilderDetector::GUTENBERG === $system ) {
			return self::write_gutenberg( $post, $anchor, $href, $data_id );
		}
		if ( BuilderDetector::CLASSIC === $system ) {
			return self::write_classic( $post, $anchor, $href, $data_id );
		}
		return array( 'ok' => false, 'reason' => 'unsupported_system' );
	}

	/**
	 * Classic editor: operate directly on post_content.
	 */
	private static function write_classic( \WP_Post $post, $anchor, $href, $data_id ) {
		$before = (string) $post->post_content;

		$res = WriteGuards::insert_anchor( $before, $anchor, $href, $data_id );
		if ( empty( $res['ok'] ) ) {
			return array( 'ok' => false, 'reason' => $res['reason'] );
		}
		$after = $res['html'];

		if ( ! self::visible_equal( $before, $after ) ) {
			return array( 'ok' => false, 'reason' => 'integrity_check_failed' );
		}

		return array(
			'ok'             => true,
			'value_before'   => $before,
			'value_after'    => $after,
			'storage_target' => 'post_content',
			'meta_key'       => '',
		);
	}

	/**
	 * Gutenberg: locate the single text-bearing leaf block and rewrite it.
	 */
	private static function write_gutenberg( \WP_Post $post, $anchor, $href, $data_id ) {
		$before = (string) $post->post_content;
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return array( 'ok' => false, 'reason' => 'blocks_unavailable' );
		}

		$blocks = parse_blocks( $before );

		// Global single-occurrence guard across eligible leaf blocks.
		$total = self::count_blocks( $blocks, $anchor );
		if ( 1 !== $total ) {
			return array( 'ok' => false, 'reason' => 0 === $total ? 'anchor_not_found' : 'anchor_ambiguous' );
		}

		$inserted = self::insert_into_blocks( $blocks, $anchor, $href, $data_id );
		if ( ! $inserted ) {
			return array( 'ok' => false, 'reason' => 'anchor_not_found' );
		}

		$after = serialize_blocks( $blocks );

		if ( ! self::visible_equal( $before, $after ) ) {
			return array( 'ok' => false, 'reason' => 'integrity_check_failed' );
		}

		return array(
			'ok'             => true,
			'value_before'   => $before,
			'value_after'    => $after,
			'storage_target' => 'post_content',
			'meta_key'       => '',
		);
	}

	/**
	 * Count eligible occurrences across leaf text blocks.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $anchor Anchor phrase.
	 * @return int
	 */
	private static function count_blocks( $blocks, $anchor ) {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$count += self::count_blocks( $block['innerBlocks'], $anchor );
				continue;
			}
			if ( self::is_text_block( $block ) ) {
				$count += WriteGuards::count_in_html( (string) $block['innerHTML'], $anchor );
			}
		}
		return $count;
	}

	/**
	 * Recursively insert into the first matching leaf block (by reference).
	 *
	 * @param array  $blocks  Parsed blocks (by reference).
	 * @param string $anchor  Anchor phrase.
	 * @param string $href    Target URL.
	 * @param string $data_id Provenance id.
	 * @return bool Whether an insertion was made.
	 */
	private static function insert_into_blocks( &$blocks, $anchor, $href, $data_id ) {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( self::insert_into_blocks( $block['innerBlocks'], $anchor, $href, $data_id ) ) {
					return true;
				}
				continue;
			}
			if ( self::is_text_block( $block ) && WriteGuards::count_in_html( (string) $block['innerHTML'], $anchor ) === 1 ) {
				$res = WriteGuards::insert_anchor( (string) $block['innerHTML'], $anchor, $href, $data_id );
				if ( ! empty( $res['ok'] ) ) {
					$block['innerHTML']    = $res['html'];
					$block['innerContent'] = array( $res['html'] );
					return true;
				}
			}
		}
		unset( $block );
		return false;
	}

	/**
	 * Whether a leaf block is safe to link into: an allowlisted core text block
	 * whose innerContent is exactly one string chunk (no inner-block placeholders),
	 * so replacing it cannot drop or reorder sibling content.
	 *
	 * @param array $block Block.
	 * @return bool
	 */
	private static function is_text_block( $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
		if ( ! in_array( $name, self::ALLOWED_BLOCKS, true ) ) {
			return false;
		}
		$inner_content = isset( $block['innerContent'] ) ? $block['innerContent'] : null;
		if ( ! is_array( $inner_content ) || 1 !== count( $inner_content ) || ! is_string( $inner_content[0] ) ) {
			return false;
		}
		return '' !== trim( (string) ( $block['innerHTML'] ?? '' ) );
	}

	/**
	 * Compare the visible (tag-stripped, entity-decoded) text of two fragments.
	 *
	 * @param string $a Before.
	 * @param string $b After.
	 * @return bool
	 */
	private static function visible_equal( $a, $b ) {
		return self::normalize_visible( $a ) === self::normalize_visible( $b );
	}

	/**
	 * @param string $html HTML.
	 * @return string
	 */
	private static function normalize_visible( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}
}
