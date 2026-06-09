<?php
/**
 * Wrap-first anchor finder: locate an existing phrase in the SOURCE text that can
 * naturally become the anchor for a link to the target. Prefers descriptive
 * multi-word phrases (2-4 words) drawn from the target title; single words are a
 * gated fallback only. Never fabricates text — returns null when nothing natural
 * exists.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class AnchorGenerator {

	const MAX_CANDIDATES = 60;

	/**
	 * Find a natural anchor for a target within the source text.
	 *
	 * @param string $source_text  Source plain text.
	 * @param string $target_title Target post title.
	 * @param int    $min_words    Minimum anchor words (1 enables single-word fallback).
	 * @param int    $max_words    Maximum anchor words.
	 * @return array{anchor:string,context:string,offset:int}|null
	 */
	public static function find( $source_text, $target_title, $min_words = 2, $max_words = 4 ) {
		foreach ( self::candidate_phrases( $target_title, $min_words, $max_words ) as $phrase ) {
			$hit = self::locate( $source_text, $phrase );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Build candidate anchor phrases from a title, most-specific first:
	 * longer multi-word phrases before shorter, single words last (if allowed).
	 *
	 * @param string $title     Target title.
	 * @param int    $min_words Minimum words.
	 * @param int    $max_words Maximum words.
	 * @return string[]
	 */
	private static function candidate_phrases( $title, $min_words, $max_words ) {
		$min_words = max( 1, (int) $min_words );
		$max_words = max( $min_words, (int) $max_words );

		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			return array();
		}

		// Break into clauses so phrases never cross punctuation boundaries
		// (e.g. don't bridge "Part 10: Geography ...").
		$segments = preg_split( '/[:;,|()\[\]\/]+|[\x{2013}\x{2014}-]+/u', $title );

		$candidates = array(); // phrase => word_count
		$lower_min  = max( 2, $min_words ); // phrase loop only builds multi-word grams

		foreach ( (array) $segments as $seg ) {
			if ( ! preg_match_all( '/[\p{L}\p{Nd}]+/u', $seg, $mm ) ) {
				continue;
			}
			$words = $mm[0];
			$n     = count( $words );

			for ( $size = min( $max_words, $n ); $size >= $lower_min; $size-- ) {
				for ( $i = 0; $i + $size <= $n; $i++ ) {
					$slice = array_slice( $words, $i, $size );
					if ( self::is_noise_phrase( $slice ) ) {
						continue;
					}
					$phrase = implode( ' ', $slice );
					if ( strlen( $phrase ) < 6 ) {
						continue;
					}
					$candidates[ $phrase ] = $size;
				}
			}
		}

		$phrases = array_keys( $candidates );
		usort(
			$phrases,
			function ( $a, $b ) use ( $candidates ) {
				if ( $candidates[ $a ] !== $candidates[ $b ] ) {
					return $candidates[ $b ] - $candidates[ $a ];
				}
				return strlen( $b ) - strlen( $a );
			}
		);

		if ( count( $phrases ) > self::MAX_CANDIDATES ) {
			$phrases = array_slice( $phrases, 0, self::MAX_CANDIDATES );
		}

		// Single-word fallback only when explicitly allowed.
		if ( $min_words <= 1 ) {
			foreach ( self::single_words( $title ) as $word ) {
				$phrases[] = $word;
			}
		}

		return $phrases;
	}

	/**
	 * Distinctive single words from a title (fallback anchors).
	 *
	 * @param string $title Title.
	 * @return string[]
	 */
	private static function single_words( $title ) {
		$generic = array( 'guide', 'review', 'tips', 'best', 'introduction', 'overview', 'about', 'guidelines' );
		$out     = array();
		if ( preg_match_all( '/\p{L}{6,}/u', $title, $m ) ) {
			foreach ( $m[0] as $word ) {
				$lw = self::lc( $word );
				if ( ! isset( self::stopwords()[ $lw ] ) && ! in_array( $lw, $generic, true ) ) {
					$out[ $word ] = true;
				}
			}
		}
		$out = array_keys( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $out;
	}

	/**
	 * Whether a phrase is low quality as an anchor (edge stopword, number, or
	 * structural noise token like "Part 10").
	 *
	 * @param string[] $words Phrase words.
	 * @return bool
	 */
	private static function is_noise_phrase( $words ) {
		$stop  = self::stopwords();
		$noise = array( 'part', 'parts', 'vol', 'volume', 'chapter', 'edition', 'section', 'no' );

		$first = self::lc( $words[0] );
		$last  = self::lc( $words[ count( $words ) - 1 ] );
		if ( isset( $stop[ $first ] ) || isset( $stop[ $last ] ) ) {
			return true;
		}

		foreach ( $words as $w ) {
			if ( preg_match( '/^\p{Nd}+$/u', $w ) ) {
				return true; // pure number token
			}
			if ( in_array( self::lc( $w ), $noise, true ) ) {
				return true;
			}
		}
		return false;
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
		$pattern = '/(?<![\p{L}\p{Nd}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{Nd}])/ui';
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
	 * Extract a readable context window around an offset.
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
	 * @return string Lowercased (multibyte-aware where available).
	 */
	private static function lc( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Small stopword set for phrase-edge trimming.
	 *
	 * @return array<string,bool>
	 */
	private static function stopwords() {
		static $set = null;
		if ( null !== $set ) {
			return $set;
		}
		$words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'of', 'in', 'on', 'to', 'for', 'with', 'by', 'at', 'from', 'as', 'into', 'their', 'its', 'his', 'her', 'our', 'your', 'this', 'that', 'these', 'those', 'is', 'are', 'was', 'were', 'be', 'been', 'how', 'what', 'why', 'when', 'which', 'who', 'their', 'them', 'they', 'it', 'than', 'then', 'over', 'under', 'about' );
		$set   = array();
		foreach ( $words as $w ) {
			$set[ $w ] = true;
		}
		return $set;
	}
}
