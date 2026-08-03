<?php
/**
 * Placement-naturalness scoring. A link that reads naturally helps the reader
 * (and rankings) more than one jammed in for SEO. This judges the anchor phrase
 * itself: is it descriptive, is it a sensible length, or is it a bare word or a
 * whole clause.
 *
 * It deliberately knows nothing about relevance. An earlier version started from
 * `0.45 + relevance * 0.30`, which made the two scores restatements of each
 * other and quietly broke the composite below. Measured across 170 real
 * suggestions on a live site, `naturalness - 0.30 * relevance` came out as
 * exactly 0.700 on 161 of them: the anchor-shape part took only three distinct
 * values in the entire corpus, and confidence correlated with relevance at
 * 0.9902 — it was adding almost nothing. Keeping the two independent is what
 * makes the weighting in confidence() mean what it says.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class Naturalness {

	/** Neutral starting point, before anything about the anchor is considered. */
	const BASE = 0.55;

	/** A descriptive phrase of this many words reads best as a link. */
	const IDEAL_MIN_WORDS = 2;
	const IDEAL_MAX_WORDS = 4;

	/** Beyond this an anchor is a clause, not a phrase. */
	const CLAUSE_WORDS = 5;

	/** Comfortable character length for a link. */
	const GOOD_MIN_CHARS = 8;
	const GOOD_MAX_CHARS = 60;

	/** Below this an anchor is too small to be a useful click target. */
	const TINY_CHARS = 5;

	/** Above this it is a sentence. */
	const HUGE_CHARS = 70;

	/**
	 * Score an anchor phrase in [0,1].
	 *
	 * @param string     $anchor  The anchor phrase.
	 * @param float|null $ignored Formerly relevance. Accepted so existing callers
	 *                            and any third-party code keep working, and
	 *                            deliberately unused: mixing relevance in here is
	 *                            the defect this version removes.
	 * @return float
	 */
	public static function score( $anchor, $ignored = null ) {
		$anchor = trim( (string) $anchor );
		if ( '' === $anchor ) {
			return 0.0;
		}

		$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $anchor, 'UTF-8' ) : strlen( $anchor );
		$parts = preg_split( '/\s+/u', $anchor );
		$words = is_array( $parts ) ? count( $parts ) : 1;

		$score = self::BASE;

		// Shape. A two-to-four word phrase tells a reader where the link goes.
		if ( $words >= self::IDEAL_MIN_WORDS && $words <= self::IDEAL_MAX_WORDS ) {
			$score += 0.20;
		} elseif ( $words >= self::CLAUSE_WORDS ) {
			$score -= 0.15;
		}
		// A single word is left neutral rather than penalised. Chinese, Japanese
		// and Thai do not put spaces between words, so every anchor in those
		// scripts counts as one "word"; penalising that would mark down every
		// suggestion on such a site for a property of its writing system.

		// Length, counted in characters. This used to be strlen(), which counts
		// bytes: a three-character Devanagari or Arabic anchor measures nine
		// bytes, so it escaped the too-short penalty and could even collect the
		// good-length bonus, making the whole check meaningless off the Latin
		// alphabet.
		if ( $chars >= self::GOOD_MIN_CHARS && $chars <= self::GOOD_MAX_CHARS ) {
			$score += 0.15;
		}
		if ( $chars < self::TINY_CHARS ) {
			$score -= 0.30;
		}
		if ( $chars > self::HUGE_CHARS ) {
			$score -= 0.25;
		}

		/**
		 * Filter the naturalness score for an anchor.
		 *
		 * @param float  $score  Score in [0,1].
		 * @param string $anchor Anchor phrase.
		 * @param int    $words  Word count.
		 * @param int    $chars  Character count.
		 */
		$score = (float) apply_filters( 'ailinking_naturalness', $score, $anchor, $words, $chars );

		return max( 0.0, min( 1.0, round( $score, 4 ) ) );
	}

	/**
	 * Composite confidence from relevance and naturalness.
	 *
	 * Now genuinely 60/40. While naturalness carried a relevance term, relevance
	 * was counted twice — 0.6 directly plus 0.4 * 0.3 through naturalness — so
	 * the real weighting was 0.72/0.28 and the figure shown to a reviewer moved
	 * almost only with relevance.
	 *
	 * @param float $relevance   Relevance [0,1].
	 * @param float $naturalness Naturalness [0,1].
	 * @return float
	 */
	public static function confidence( $relevance, $naturalness ) {
		$relevance   = max( 0.0, min( 1.0, (float) $relevance ) );
		$naturalness = max( 0.0, min( 1.0, (float) $naturalness ) );

		/**
		 * Filter the weight given to relevance in the composite. The remainder
		 * goes to naturalness.
		 *
		 * @param float $weight Relevance weight in [0,1].
		 */
		$w = (float) apply_filters( 'ailinking_relevance_weight', 0.6 );
		$w = max( 0.0, min( 1.0, $w ) );

		return round( ( $w * $relevance ) + ( ( 1.0 - $w ) * $naturalness ), 4 );
	}
}
