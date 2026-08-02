<?php
/**
 * The single chokepoint for AI calls. Resolves provider+model from settings,
 * enforces the monthly spend cap, leases keys from the pool, and runs the
 * retry/failover loop — returning one normalized shape regardless of provider.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Gateway {

	const MAX_KEYS    = 12;
	const MAX_RETRIES = 3;

	/**
	 * Decide what to do after a provider error (pure; unit-tested).
	 *
	 * @param array $error Normalized error.
	 * @return string 'failover' | 'retry' | 'stop'
	 */
	public static function next_action( array $error ) {
		if ( ! empty( $error['triggers_failover'] ) ) {
			return 'failover';
		}
		if ( ! empty( $error['is_retryable'] ) ) {
			return 'retry';
		}
		return 'stop';
	}

	/**
	 * Run a chat completion.
	 *
	 * @param array $request Normalized chat request.
	 * @return array Normalized response, or ['ok'=>false,'error'=>...].
	 */
	public static function chat( array $request ) {
		$provider_id = (string) Settings::get( 'chat_provider', 'none' );
		$model_cfg   = (string) Settings::get( 'chat_model', '' );
		return self::run( 'chat', $provider_id, $model_cfg, $request );
	}

	/**
	 * Whether a chat provider is configured (powers generative suggestions).
	 * Does not verify a key is in the pool — the run loop degrades gracefully
	 * if no usable key is present.
	 *
	 * @return bool
	 */
	public static function chat_enabled() {
		$provider_id = (string) Settings::get( 'chat_provider', 'none' );
		if ( 'none' === $provider_id ) {
			return false;
		}
		$provider = Registry::get( $provider_id );
		return $provider && $provider->supports_chat();
	}

	/**
	 * Shared call runner with cap check + failover.
	 *
	 * @param string $plane       Operation label, recorded against spend ('chat').
	 * @param string $provider_id Provider slug.
	 * @param string $model_cfg   Configured model fallback.
	 * @param array  $request     Request payload.
	 * @return array
	 */
	private static function run( $plane, $provider_id, $model_cfg, array $request ) {
		if ( 'none' === $provider_id ) {
			return array( 'ok' => false, 'error' => array( 'class' => 'disabled', 'message' => 'AI provider is set to none' ) );
		}
		$provider = Registry::get( $provider_id );
		if ( ! $provider ) {
			return array( 'ok' => false, 'error' => array( 'class' => 'disabled', 'message' => 'Unknown provider' ) );
		}

		// Spend cap pre-flight.
		$cap_cents = (int) round( (float) Settings::get( 'monthly_cap_usd', 0 ) * 100 );
		if ( $cap_cents > 0 ) {
			$est = self::estimate_cents( $plane, $provider_id, $model_cfg, $request );
			if ( KeyPoolManager::monthly_spend_cents( 'all' ) + $est > $cap_cents ) {
				return array( 'ok' => false, 'error' => array( 'class' => 'budget', 'message' => 'Monthly AI spend cap reached' ) );
			}
		}

		$rotation = (string) Settings::get( 'rotation', 'round_robin' );
		$skip     = array();
		$last_err = null;
		$guard    = 0;

		while ( $guard++ < self::MAX_KEYS ) {
			$lease = KeyPoolManager::lease( $provider_id, $plane, $rotation, $skip );
			if ( ! $lease ) {
				break;
			}

			$ctx = array(
				'api_key'  => $lease['api_key'],
				'base_url' => '' !== $lease['base_url'] ? $lease['base_url'] : $provider->default_base_url(),
				'model'    => self::pick_model( $lease['model'], $model_cfg, $provider, $plane ),
				'timeout'  => 30,
				'extra'    => $lease['extra'],
			);

			$err = null;
			for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
				$resp = $provider->chat( $request, $ctx );

				if ( ! empty( $resp['ok'] ) ) {
					self::record_success( $plane, $provider_id, $ctx['model'], $lease['key_id'], $resp );
					$resp['provider'] = $provider_id;
					$resp['key_id']   = $lease['key_id'];
					return $resp;
				}

				$err      = isset( $resp['error'] ) ? $resp['error'] : array( 'class' => 'unknown' );
				$last_err = $err;
				$action   = self::next_action( $err );

				if ( 'retry' === $action ) {
					// Back off before retrying the same key (honor retry_after, capped).
					$wait = ! empty( $err['retry_after'] ) ? min( 5, (int) $err['retry_after'] ) : min( 4, 1 << $attempt );
					if ( $wait > 0 ) {
						sleep( $wait );
					}
					continue; // same key.
				}
				if ( 'stop' === $action ) {
					KeyPoolManager::report_error( $lease['key_id'], $err, $provider_id );
					return array( 'ok' => false, 'error' => $err );
				}
				break; // failover.
			}

			KeyPoolManager::report_error( $lease['key_id'], $err ? $err : array( 'class' => 'unknown' ), $provider_id );
			$skip[] = $lease['key_id'];
		}

		return array( 'ok' => false, 'error' => $last_err ? $last_err : array( 'class' => 'no_key', 'message' => 'No usable keys in the pool' ) );
	}

	/**
	 * Record spend for a successful call.
	 */
	private static function record_success( $plane, $provider_id, $model, $key_id, array $resp ) {
		$tin  = isset( $resp['usage']['input_tokens'] ) ? (int) $resp['usage']['input_tokens'] : 0;
		$tout = isset( $resp['usage']['output_tokens'] ) ? (int) $resp['usage']['output_tokens'] : 0;
		$cents = Pricing::cents( $provider_id, $model, $tin, $tout, $plane );
		$exact = Pricing::cents_float( $provider_id, $model, $tin, $tout, $plane );
		KeyPoolManager::report_success( $key_id, $provider_id, $model, $plane, $tin, $tout, $cents, $exact );
	}

	/**
	 * Estimate cost for the cap pre-flight from request size.
	 */
	private static function estimate_cents( $plane, $provider_id, $model, array $request ) {
		$chars = 0;
		if ( ! empty( $request['system'] ) ) {
			$chars += strlen( (string) $request['system'] );
		}
		foreach ( (array) $request['messages'] as $m ) {
			$chars += isset( $m['content'] ) ? strlen( (string) $m['content'] ) : 0;
		}
		$out = isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 256;
		return Pricing::cents( $provider_id, $model, (int) ceil( $chars / 4 ), $out, 'chat' );
	}

	/**
	 * Choose the effective model: key model, else configured, else first default.
	 */
	private static function pick_model( $key_model, $cfg_model, ProviderInterface $provider, $plane ) {
		if ( '' !== (string) $key_model ) {
			return $key_model;
		}
		if ( '' !== (string) $cfg_model ) {
			return $cfg_model;
		}
		$models = $provider->default_models();
		$list   = isset( $models['chat'] ) ? $models['chat'] : array();
		return ! empty( $list ) ? $list[0] : '';
	}
}
