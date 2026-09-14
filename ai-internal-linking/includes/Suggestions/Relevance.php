<?php
/**
 * Putting three engines' scores on one scale.
 *
 * Each engine measures something different and says so with a number, and all
 * three numbers used to be written into the same relevance_score column and
 * sorted against each other:
 *
 * - TF-IDF reports a cosine similarity. Real measurement, but small: useful
 *   matches sit somewhere around 0.1 to 0.5.
 * - The keyword engine reports 0.5 + bonuses, so it can never report less than
 *   0.5 whatever the evidence.
 * - The AI engine reported whatever confidence the model typed into its reply,
 *   defaulting to 0.7 when it typed none. That is the model's opinion of itself,
 *   not a measurement of anything.
 *
 * The review screen sorts by confidence descending, so the effect was an order
 * of engines wearing the costume of an order of quality: a TF-IDF suggestion
 * could not reach the top of the queue however good the link was, and an AI
 * suggestion that omitted a confidence outranked it by default.
 *
 * This maps each engine's own operating range onto one shared band so the
 * number means the same thing wherever it came from. That is calibration, not
 * measurement: the ranges are honest estimates of where each engine actually
 * operates, they are filterable, and the raw figure is kept alongside so
 * nothing is lost and the screen can still show what the engine itself said.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class Relevance {

	/** Bottom of the shared band. Nothing calibrates to zero. */
	const FLOOR = 0.10;

	/** Top of the shared band. Nothing calibrates to one. */
	const CEILING = 0.95;

	/**
	 * Where each engine actually operates, as [low, high] in its own units.
	 * A score at `low` calibrates to FLOOR, one at `high` to CEILING.
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	public static function ranges() {
		$ranges = array(
			// Cosine similarity. Below ~0.05 is noise; above ~0.60 is a near
			// duplicate, which is rare and not more useful than a strong match.
			'tfidf'   => array( 0.05, 0.60 ),

			// Cannot report below 0.5 by construction, so 0.5 is its zero.
			'keyword' => array( 0.50, 0.98 ),

			// Since 0.27.1 the AI engine reports the measured similarity of the
			// page the model chose, not the confidence the model claimed for
			// itself, so it is on the cosine scale like Related Content. The
			// model's own figure is kept alongside for display but is not what
			// gets ranked: an opinion about a pick is not evidence for it.
			'llm'     => array( 0.05, 0.60 ),
		);

		/**
		 * Filter the per-engine operating ranges used for calibration.
		 *
		 * @param array $ranges engine => [low, high].
		 */
		return (array) apply_filters( 'ailinking_relevance_ranges', $ranges );
	}

	/**
	 * Map an engine's native score onto the shared band.
	 *
	 * An engine with no declared range is passed through unchanged and merely
	 * clamped, so a third-party engine is never silently rescaled.
	 *
	 * @param string $engine Engine key.
	 * @param float  $raw    The engine's own score.
	 * @return float Calibrated score in [FLOOR, CEILING].
	 */
	public static function calibrate( $engine, $raw ) {
		$raw    = (float) $raw;
		$ranges = self::ranges();
		$engine = (string) $engine;

		if ( ! isset( $ranges[ $engine ] ) ) {
			return round( max( 0.0, min( 1.0, $raw ) ), 4 );
		}

		list( $low, $high ) = $ranges[ $engine ];
		$low  = (float) $low;
		$high = (float) $high;

		if ( $high <= $low ) {
			return round( max( 0.0, min( 1.0, $raw ) ), 4 );
		}

		$position = ( $raw - $low ) / ( $high - $low );
		$position = max( 0.0, min( 1.0, $position ) );

		return round( self::FLOOR + ( $position * ( self::CEILING - self::FLOOR ) ), 4 );
	}

	/**
	 * Whether an engine's score is calibrated rather than passed through, so the
	 * screen can say which it is showing.
	 *
	 * @param string $engine Engine key.
	 * @return bool
	 */
	public static function is_calibrated( $engine ) {
		$ranges = self::ranges();
		return isset( $ranges[ (string) $engine ] );
	}
}
