<?php
/**
 * Google Gemini adapter (Generative Language API). Chat + embeddings; key passed
 * as a query parameter.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class GeminiProvider implements ProviderInterface {

	public function id() {
		return 'gemini';
	}

	public function label() {
		return 'Google (Gemini)';
	}

	public function supports_chat() {
		return true;
	}

	public function supports_embeddings() {
		return true;
	}

	public function default_base_url() {
		return 'https://generativelanguage.googleapis.com/v1beta';
	}

	public function needs_base_url() {
		return false;
	}

	public function default_models() {
		return array(
			'chat'      => array( 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro' ),
			'embedding' => array( 'text-embedding-004' ),
		);
	}

	public function chat( array $request, array $ctx ) {
		$contents = array();
		foreach ( (array) $request['messages'] as $m ) {
			$contents[] = array(
				'role'  => ( 'assistant' === $m['role'] ) ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) $m['content'] ) ),
			);
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 512,
				'temperature'     => isset( $request['temperature'] ) ? (float) $request['temperature'] : 0.2,
			),
		);
		if ( ! empty( $request['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
		}

		$url     = $this->base( $ctx ) . '/models/' . rawurlencode( $ctx['model'] ) . ':generateContent';
		$res     = Http::post( $url, $this->headers( $ctx ), wp_json_encode( $body ), $this->timeout( $ctx ) );
		$decoded = json_decode( (string) $res['body'], true );

		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
		}

		$text = '';
		if ( isset( $decoded['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		return array(
			'ok'            => true,
			'text'          => $text,
			'finish_reason' => isset( $decoded['candidates'][0]['finishReason'] ) ? strtolower( (string) $decoded['candidates'][0]['finishReason'] ) : 'stop',
			'usage'         => array(
				'input_tokens'  => isset( $decoded['usageMetadata']['promptTokenCount'] ) ? (int) $decoded['usageMetadata']['promptTokenCount'] : 0,
				'output_tokens' => isset( $decoded['usageMetadata']['candidatesTokenCount'] ) ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : 0,
			),
			'model'         => $ctx['model'],
		);
	}

	public function embed( array $request, array $ctx ) {
		$vectors = array();
		$tokens  = 0;

		// Gemini embeds one content per call; loop the (typically small) input set.
		foreach ( (array) $request['input'] as $text ) {
			$url  = $this->base( $ctx ) . '/models/' . rawurlencode( $ctx['model'] ) . ':embedContent';
			$body = array(
				'model'   => 'models/' . $ctx['model'],
				'content' => array( 'parts' => array( array( 'text' => (string) $text ) ) ),
			);
			$res     = Http::post( $url, $this->headers( $ctx ), wp_json_encode( $body ), $this->timeout( $ctx ) );
			$decoded = json_decode( (string) $res['body'], true );

			if ( $res['status'] < 200 || $res['status'] >= 300 ) {
				return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
			}
			$vectors[] = isset( $decoded['embedding']['values'] ) ? array_map( 'floatval', $decoded['embedding']['values'] ) : array();
			$tokens   += (int) ceil( strlen( (string) $text ) / 4 ); // Gemini omits usage; estimate.
		}

		return array(
			'ok'      => true,
			'vectors' => $vectors,
			'usage'   => array( 'input_tokens' => $tokens ),
			'model'   => $ctx['model'],
		);
	}

	private function base( array $ctx ) {
		$base = ! empty( $ctx['base_url'] ) ? $ctx['base_url'] : $this->default_base_url();
		return rtrim( (string) $base, '/' );
	}

	private function headers( array $ctx ) {
		// Key in a header (not the URL) so it never lands in request/proxy logs.
		return array(
			'Content-Type'    => 'application/json',
			'x-goog-api-key'  => isset( $ctx['api_key'] ) ? (string) $ctx['api_key'] : '',
		);
	}

	private function timeout( array $ctx ) {
		return isset( $ctx['timeout'] ) ? (int) $ctx['timeout'] : 30;
	}
}
