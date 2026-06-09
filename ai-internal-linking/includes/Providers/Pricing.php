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
	 * @param string $operation  'chat'|'embedding'.
	 * @return int
	 */
	public static function cents( $provider, $model, $tokens_in, $tokens_out, $operation = 'chat' ) {
		$rates = self::rate_for( $provider, $model, $operation );
		$cost  = ( (int) $tokens_in * $rates['in'] + (int) $tokens_out * $rates['out'] ) / 1000000.0;
		return (int) ceil( $cost );
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
		// Defaults: conservative so the cap never under-counts unknown models.
		$default_chat  = array( 'in' => 50.0, 'out' => 150.0 );
		$default_embed = array( 'in' => 2.0, 'out' => 0.0 );

		$table = array(
			// provider/model substring => [in, out] cents per 1M tokens (approx).
			'openai/gpt-4o-mini'           => array( 'in' => 15.0, 'out' => 60.0 ),
			'openai/gpt-4o'                => array( 'in' => 250.0, 'out' => 1000.0 ),
			'openai/text-embedding-3-small' => array( 'in' => 2.0, 'out' => 0.0 ),
			'openai/text-embedding-3-large' => array( 'in' => 13.0, 'out' => 0.0 ),
			'anthropic/claude-haiku'       => array( 'in' => 80.0, 'out' => 400.0 ),
			'anthropic/claude-sonnet'      => array( 'in' => 300.0, 'out' => 1500.0 ),
			'gemini/gemini-1.5-flash'      => array( 'in' => 7.5, 'out' => 30.0 ),
			'voyage/voyage-3-lite'         => array( 'in' => 2.0, 'out' => 0.0 ),
		);

		$table = apply_filters( 'ailinking_model_pricing', $table );

		$needle_full = $provider . '/' . $model;
		foreach ( $table as $key => $rate ) {
			if ( false !== strpos( $needle_full, $key ) || ( '' !== $model && false !== strpos( $key, $model ) ) ) {
				return $rate;
			}
		}

		return ( 'embedding' === $operation ) ? $default_embed : $default_chat;
	}
}
