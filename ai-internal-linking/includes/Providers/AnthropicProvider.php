<?php
/**
 * Anthropic (Claude) adapter. Chat only.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class AnthropicProvider implements ProviderInterface {

	public function id() {
		return 'anthropic';
	}

	public function label() {
		return 'Anthropic (Claude)';
	}

	public function supports_chat() {
		return true;
	}

	public function default_base_url() {
		return 'https://api.anthropic.com/v1';
	}

	public function needs_base_url() {
		return false;
	}

	/**
	 * Models offered in the Providers screen. The first is used when a key has
	 * no model of its own and no model is configured.
	 */
	public function default_models() {
		return array(
			'chat'      => array( 'claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6' ),
		);
	}

	/**
	 * Whether an error reply is the API refusing a parameter we sent. (pure)
	 *
	 * Newer Claude models reject `temperature` outright rather than ignoring it,
	 * so a request that worked for a year starts failing the day you switch
	 * model — with a 400 that looks like a bad request, not like a model that
	 * needs different options. Recognising it is what lets the call be retried
	 * without the parameter instead of surfacing as "your key is broken".
	 *
	 * @param int    $status  HTTP status.
	 * @param mixed  $decoded Decoded JSON body, if any.
	 * @param string $raw     Raw body.
	 * @return bool
	 */
	public static function is_temperature_error( $status, $decoded, $raw = '' ) {
		if ( 400 !== (int) $status ) {
			return false;
		}
		$message = '';
		if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
			$message = (string) $decoded['error']['message'];
		} elseif ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
			$message = (string) $decoded['message'];
		}
		if ( '' === $message ) {
			$message = (string) $raw;
		}
		$message = strtolower( $message );

		return false !== strpos( $message, 'temperature' )
			&& ( false !== strpos( $message, 'deprecated' )
				|| false !== strpos( $message, 'not supported' )
				|| false !== strpos( $message, 'unsupported' )
				|| false !== strpos( $message, 'unexpected' )
				|| false !== strpos( $message, 'not permitted' )
				|| false !== strpos( $message, 'cannot be' ) );
	}

	/**
	 * Option key recording models known to reject `temperature`.
	 *
	 * Remembered rather than hard-coded: a fixed list of model names goes stale
	 * the moment a new one ships, and this plugin cannot be updated on the day
	 * that happens. Learning it from the API's own answer means a brand new
	 * model costs one rejected request (which bills nothing) and works after.
	 */
	const NO_TEMPERATURE_OPTION = 'ailinking_no_temperature_models';

	/**
	 * Whether this model has already told us it will not take a temperature.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private static function omits_temperature( $model ) {
		$known = get_option( self::NO_TEMPERATURE_OPTION, array() );
		return is_array( $known ) && in_array( (string) $model, $known, true );
	}

	/**
	 * Remember that a model rejects `temperature`, so later calls skip it.
	 *
	 * @param string $model Model id.
	 * @return void
	 */
	private static function remember_omits_temperature( $model ) {
		$known = get_option( self::NO_TEMPERATURE_OPTION, array() );
		if ( ! is_array( $known ) ) {
			$known = array();
		}
		if ( ! in_array( (string) $model, $known, true ) ) {
			$known[] = (string) $model;
			// Bounded: this is a cache, not a record worth growing forever.
			if ( count( $known ) > 50 ) {
				$known = array_slice( $known, -50 );
			}
			update_option( self::NO_TEMPERATURE_OPTION, $known, false );
		}
	}

	public function chat( array $request, array $ctx ) {
		$messages = array();
		foreach ( (array) $request['messages'] as $m ) {
			$messages[] = array(
				'role'    => ( 'assistant' === $m['role'] ) ? 'assistant' : 'user',
				'content' => $m['content'],
			);
		}

		$model = (string) $ctx['model'];
		$body  = array(
			'model'      => $model,
			'max_tokens' => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 512,
			'messages'   => $messages,
		);
		if ( ! self::omits_temperature( $model ) ) {
			$body['temperature'] = isset( $request['temperature'] ) ? (float) $request['temperature'] : 0.2;
		}
		if ( ! empty( $request['system'] ) ) {
			$body['system'] = (string) $request['system'];
		}

		$base    = ! empty( $ctx['base_url'] ) ? rtrim( $ctx['base_url'], '/' ) : 'https://api.anthropic.com/v1';
		$headers = array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => isset( $ctx['api_key'] ) ? (string) $ctx['api_key'] : '',
			'anthropic-version' => '2023-06-01',
		);
		$timeout = isset( $ctx['timeout'] ) ? (int) $ctx['timeout'] : 30;

		$res     = Http::post( $base . '/messages', $headers, wp_json_encode( $body ), $timeout );
		$decoded = json_decode( (string) $res['body'], true );

		// The model refused the temperature: drop it, remember, and try once more.
		// Determinism is the only thing lost, and these models are consistent
		// enough without it — far better than the engine going silent.
		if ( isset( $body['temperature'] )
			&& self::is_temperature_error( $res['status'], $decoded, (string) $res['body'] ) ) {
			self::remember_omits_temperature( $model );
			unset( $body['temperature'] );
			$res     = Http::post( $base . '/messages', $headers, wp_json_encode( $body ), $timeout );
			$decoded = json_decode( (string) $res['body'], true );
		}

		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
		}

		$text = '';
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$stop   = isset( $decoded['stop_reason'] ) ? (string) $decoded['stop_reason'] : 'end_turn';
		$finish = ( 'max_tokens' === $stop ) ? 'length' : 'stop';

		return array(
			'ok'            => true,
			'text'          => $text,
			'finish_reason' => $finish,
			'usage'         => array(
				'input_tokens'  => isset( $decoded['usage']['input_tokens'] ) ? (int) $decoded['usage']['input_tokens'] : 0,
				'output_tokens' => isset( $decoded['usage']['output_tokens'] ) ? (int) $decoded['usage']['output_tokens'] : 0,
			),
			'model'         => isset( $decoded['model'] ) ? (string) $decoded['model'] : $ctx['model'],
		);
	}

}
