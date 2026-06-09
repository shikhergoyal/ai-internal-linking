<?php
/**
 * Anthropic (Claude) adapter. Chat only — Anthropic has no native embeddings API
 * (the embeddings plane resolves to Voyage/OpenAI or TF-IDF instead).
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

	public function supports_embeddings() {
		return false;
	}

	public function default_base_url() {
		return 'https://api.anthropic.com/v1';
	}

	public function needs_base_url() {
		return false;
	}

	public function default_models() {
		return array(
			'chat'      => array( 'claude-sonnet-4-6', 'claude-opus-4-8', 'claude-haiku-4-5-20251001' ),
			'embedding' => array(),
		);
	}

	public function chat( array $request, array $ctx ) {
		$messages = array();
		foreach ( (array) $request['messages'] as $m ) {
			$messages[] = array(
				'role'    => ( 'assistant' === $m['role'] ) ? 'assistant' : 'user',
				'content' => $m['content'],
			);
		}

		$body = array(
			'model'       => $ctx['model'],
			'max_tokens'  => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 512,
			'temperature' => isset( $request['temperature'] ) ? (float) $request['temperature'] : 0.2,
			'messages'    => $messages,
		);
		if ( ! empty( $request['system'] ) ) {
			$body['system'] = (string) $request['system'];
		}

		$base    = ! empty( $ctx['base_url'] ) ? rtrim( $ctx['base_url'], '/' ) : 'https://api.anthropic.com/v1';
		$headers = array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => isset( $ctx['api_key'] ) ? (string) $ctx['api_key'] : '',
			'anthropic-version' => '2023-06-01',
		);

		$res     = Http::post( $base . '/messages', $headers, wp_json_encode( $body ), isset( $ctx['timeout'] ) ? (int) $ctx['timeout'] : 30 );
		$decoded = json_decode( (string) $res['body'], true );

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

	public function embed( array $request, array $ctx ) {
		return array(
			'ok'    => false,
			'error' => array(
				'class'             => 'unsupported',
				'message'           => 'Anthropic has no embeddings API; use Voyage/OpenAI or TF-IDF.',
				'is_retryable'      => false,
				'triggers_failover' => false,
				'http_status'       => 0,
				'retry_after'       => null,
			),
		);
	}
}
