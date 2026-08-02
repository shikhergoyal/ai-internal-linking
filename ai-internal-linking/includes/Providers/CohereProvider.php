<?php
/**
 * Cohere adapter (v2 chat). Best-effort; verify against a live key.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class CohereProvider implements ProviderInterface {

	public function id() {
		return 'cohere';
	}

	public function label() {
		return 'Cohere';
	}

	public function supports_chat() {
		return true;
	}

	public function default_base_url() {
		return 'https://api.cohere.com';
	}

	public function needs_base_url() {
		return false;
	}

	public function default_models() {
		return array(
			'chat'      => array( 'command-r-plus', 'command-r' ),
		);
	}

	public function chat( array $request, array $ctx ) {
		$messages = array();
		if ( ! empty( $request['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => (string) $request['system'] );
		}
		foreach ( (array) $request['messages'] as $m ) {
			$messages[] = array( 'role' => $m['role'], 'content' => $m['content'] );
		}

		$body = array(
			'model'       => $ctx['model'],
			'messages'    => $messages,
			'max_tokens'  => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 512,
			'temperature' => isset( $request['temperature'] ) ? (float) $request['temperature'] : 0.2,
		);

		$res     = Http::post( $this->base( $ctx ) . '/v2/chat', $this->headers( $ctx ), wp_json_encode( $body ), $this->timeout( $ctx ) );
		$decoded = json_decode( (string) $res['body'], true );

		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
		}

		$text = '';
		if ( isset( $decoded['message']['content'] ) && is_array( $decoded['message']['content'] ) ) {
			foreach ( $decoded['message']['content'] as $block ) {
				if ( isset( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		}

		return array(
			'ok'            => true,
			'text'          => $text,
			'finish_reason' => isset( $decoded['finish_reason'] ) ? (string) $decoded['finish_reason'] : 'stop',
			'usage'         => array(
				'input_tokens'  => isset( $decoded['usage']['tokens']['input_tokens'] ) ? (int) $decoded['usage']['tokens']['input_tokens'] : 0,
				'output_tokens' => isset( $decoded['usage']['tokens']['output_tokens'] ) ? (int) $decoded['usage']['tokens']['output_tokens'] : 0,
			),
			'model'         => $ctx['model'],
		);
	}

	private function base( array $ctx ) {
		$base = ! empty( $ctx['base_url'] ) ? $ctx['base_url'] : $this->default_base_url();
		return rtrim( (string) $base, '/' );
	}

	private function headers( array $ctx ) {
		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . ( isset( $ctx['api_key'] ) ? (string) $ctx['api_key'] : '' ),
		);
	}

	private function timeout( array $ctx ) {
		return isset( $ctx['timeout'] ) ? (int) $ctx['timeout'] : 30;
	}
}
