<?php
/**
 * Placement-naturalness scoring. A link that reads naturally helps the reader
 * (and rankings) more than one jammed in for SEO. This rewards descriptive,
 * multi-word anchors of sensible length and penalises awkward ones.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class Naturalness {

	/**
	 * Score an anchor placement in [0,1].
	 *
	 * @param string $anchor    The anchor phrase.
	 * @param float  $relevance Relevance score [0,1].
	 * @return float
	 */
	public static function score( $anchor, $relevance ) {
		$score = 0.45 + ( $relevance * 0.30 );

		$len      = strlen( $anchor );
		$is_multi = ( false !== strpos( trim( $anchor ), ' ' ) );

		if ( $is_multi ) {
			$score += 0.15;
		}
		if ( $len >= 8 && $len <= 60 ) {
			$score += 0.10;
		}
		if ( $len < 5 ) {
			$score -= 0.20;
		}
		if ( $len > 70 ) {
			$score -= 0.15;
		}

		return max( 0.0, min( 1.0, round( $score, 4 ) ) );
	}

	/**
	 * Composite confidence from relevance and naturalness.
	 *
	 * @param float $relevance   Relevance [0,1].
	 * @param float $naturalness Naturalness [0,1].
	 * @return float
	 */
	public static function confidence( $relevance, $naturalness ) {
		return round( ( 0.6 * $relevance ) + ( 0.4 * $naturalness ), 4 );
	}
}
