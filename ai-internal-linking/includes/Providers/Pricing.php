<?php
/**
 * Approximate cost estimation (cents per 1M tokens). Estimates only — the
 * provider's dashboard is authoritative. Tunable via the `ailinking_model_pricing`
 * filter.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class Pricing {

	/**
	 * Estimate cost in whole cents (rounded up, conservative for the cap).
	 *
	 * @param string $provider   Provider slug.
	 * @param string $model      Model id.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @param string $operation  Operation label ('chat').
	 * @return int
	 */
	public static function cents( $provider, $model, $tokens_in, $tokens_out, $operation = 'chat' ) {
		return (int) ceil( self::cents_float( $provider, $model, $tokens_in, $tokens_out, $operation ) );
	}

	/**
	 * Unrounded cost in cents.
	 *
	 * cents() rounds every call up to a whole cent, which is the right,
	 * conservative choice for the spend cap but wildly overstates real usage:
	 * a 2,400-token request costing 0.036c is charged a full cent, ~28x high.
	 * Reporting uses this precise value instead. (pure)
	 *
	 * @param string $provider   Provider slug.
	 * @param string $model      Model id.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @param string $operation  Operation label ('chat').
	 * @return float
	 */
	public static function cents_float( $provider, $model, $tokens_in, $tokens_out, $operation = 'chat' ) {
		$rates = self::rate_for( $provider, $model, $operation );
		return ( (int) $tokens_in * $rates['in'] + (int) $tokens_out * $rates['out'] ) / 1000000.0;
	}

	/**
	 * Resolve input/output cent rates per 1M tokens.
	 *
	 * @param string $provider  Provider.
	 * @param string $model     Model.
	 * @param string $operation Operation.
	 * @return array{in:float,out:float}
	 */
	private static function rate_for( $provider, $model, $operation ) {
		// Default: conservative so the cap never under-counts unknown models.
		$default_chat = array( 'in' => 50.0, 'out' => 150.0 );

		$table = array(
			// provider/model substring => [in, out] cents per 1M tokens (approx).
			'openai/gpt-4o-mini'      => array( 'in' => 15.0, 'out' => 60.0 ),
			'openai/gpt-4o'           => array( 'in' => 250.0, 'out' => 1000.0 ),
			'anthropic/claude-haiku'  => array( 'in' => 80.0, 'out' => 400.0 ),
			'anthropic/claude-sonnet' => array( 'in' => 300.0, 'out' => 1500.0 ),
			// Opus was missing entirely, so it fell through to the generic
			// default and was priced ~30x under what it actually costs. That is
			// the one direction the spend cap must never be wrong in.
			'anthropic/claude-opus'   => array( 'in' => 1500.0, 'out' => 7500.0 ),
			'gemini/gemini-1.5-flash' => array( 'in' => 7.5, 'out' => 30.0 ),
		);

		$table = apply_filters( 'ailinking_model_pricing', $table );

		$needle_full = $provider . '/' . $model;
		foreach ( $table as $key => $rate ) {
			if ( false !== strpos( $needle_full, $key ) || ( '' !== $model && false !== strpos( $key, $model ) ) ) {
				return $rate;
			}
		}

		return $default_chat;
	}
}
