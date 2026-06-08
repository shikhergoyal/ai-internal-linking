<?php
/**
 * Wrap-first anchor finder: locate an existing phrase in the SOURCE text that can
 * naturally become the anchor for a link to the target. Never fabricates text —
 * if no natural phrase exists, returns null (AI snippet generation is a separate,
 * gated, opt-in feature for a later phase).
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class AnchorGenerator {

	/**
	 * Find a natural anchor for a target within the source text.
	 *
	 * @param string $source_text  Source plain text.
	 * @param string $target_title Target post title.
	 * @return array{anchor:string,context:string,offset:int}|null
	 */
	public static function find( $source_text, $target_title ) {
		$phrases = self::candidate_phrases( $target_title );

		foreach ( $phrases as $phrase ) {
			$hit = self::locate( $source_text, $phrase );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Build candidate anchor phrases from a title, longest/most-specific first.
	 *
	 * @param string $title Target title.
	 * @return string[]
	 */
	private static function candidate_phrases( $title ) {
		$title = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $title ) ) );
		if ( '' === $title ) {
			return array();
		}

		$phrases = array();

		// Full title (only if a reasonable anchor length).
		if ( self::word_count( $title ) <= 8 && strlen( $title ) >= 4 ) {
			$phrases[] = $title;
		}

		// Title with a leading article/stopword removed.
		$trimmed = preg_replace( '/^(the|a|an|how to|what is|why|guide to)\s+/i', '', $title );
		if ( $trimmed !== $title && strlen( $trimmed ) >= 4 && self::word_count( $trimmed ) <= 8 ) {
			$phrases[] = $trimmed;
		}

		// Significant individual words (length >= 6, not generic).
		$generic = array( 'guide', 'review', 'tips', 'guide', 'best', 'introduction', 'overview', 'about' );
		preg_match_all( '/\p{L}{6,}/u', $title, $m );
		if ( ! empty( $m[0] ) ) {
			foreach ( $m[0] as $word ) {
				$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
				if ( ! in_array( $lower, $generic, true ) ) {
					$phrases[] = $word;
				}
			}
		}

		// De-duplicate while preserving order; longest first for specificity.
		$phrases = array_values( array_unique( $phrases ) );
		usort(
			$phrases,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $phrases;
	}

	/**
	 * Locate a phrase in the source text as a whole-word, case-insensitive match.
	 *
	 * @param string $text   Source text.
	 * @param string $phrase Phrase to find.
	 * @return array{anchor:string,context:string,offset:int}|null
	 */
	private static function locate( $text, $phrase ) {
		if ( strlen( $phrase ) < 4 ) {
			return null;
		}
		$pattern = '/\b' . preg_quote( $phrase, '/' ) . '\b/ui';
		if ( ! preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$matched = $m[0][0];
		$offset  = (int) $m[0][1];

		return array(
			'anchor'  => $matched,
			'context' => self::context( $text, $offset, strlen( $matched ) ),
			'offset'  => $offset,
		);
	}

	/**
	 * Extract a readable sentence-ish context window around an offset.
	 *
	 * @param string $text   Source text.
	 * @param int    $offset Byte offset of the match.
	 * @param int    $len    Length of the matched anchor.
	 * @return string
	 */
	private static function context( $text, $offset, $len ) {
		$start = max( 0, $offset - 90 );
		$end   = min( strlen( $text ), $offset + $len + 90 );
		$slice = substr( $text, $start, $end - $start );

		if ( $start > 0 ) {
			$slice = '…' . ltrim( $slice );
		}
		if ( $end < strlen( $text ) ) {
			$slice = rtrim( $slice ) . '…';
		}
		return trim( preg_replace( '/\s+/u', ' ', $slice ) );
	}

	/**
	 * @param string $s String.
	 * @return int Word count.
	 */
	private static function word_count( $s ) {
		return (int) preg_match_all( '/\p{L}+/u', $s, $ignore );
	}
}
