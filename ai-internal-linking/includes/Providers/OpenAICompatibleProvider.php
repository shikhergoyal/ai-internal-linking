<?php
/**
 * Adapter for any provider speaking the OpenAI wire format. Configured instances
 * cover OpenAI, OpenRouter, Mistral, Groq, Together, Fireworks, DeepSeek, xAI,
 * Perplexity, a user "custom" endpoint, and local servers (Ollama/LM Studio/vLLM).
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class OpenAICompatibleProvider implements ProviderInterface {

	protected $id;
	protected $label;
	protected $base_url;
	protected $supports_chat;
	protected $supports_embeddings;
	protected $needs_base_url;
	protected $auth_style;     // bearer | api_key_header | none
	protected $extra_headers;  // assoc
	protected $chat_models;
	protected $embed_models;

	/**
	 * @param string $id    Slug.
	 * @param string $label Human label.
	 * @param string $base  Default base URL.
	 * @param array  $opts  Options.
	 */
	public function __construct( $id, $label, $base, array $opts = array() ) {
		$this->id                  = $id;
		$this->label               = $label;
		$this->base_url            = $base;
		$this->supports_chat       = array_key_exists( 'supports_chat', $opts ) ? (bool) $opts['supports_chat'] : true;
		$this->supports_embeddings = ! empty( $opts['supports_embeddings'] );
		$this->needs_base_url      = ! empty( $opts['needs_base_url'] );
		$this->auth_style          = isset( $opts['auth_style'] ) ? $opts['auth_style'] : 'bearer';
		$this->extra_headers       = isset( $opts['extra_headers'] ) ? (array) $opts['extra_headers'] : array();
		$this->chat_models         = isset( $opts['chat_models'] ) ? (array) $opts['chat_models'] : array();
		$this->embed_models        = isset( $opts['embed_models'] ) ? (array) $opts['embed_models'] : array();
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return $this->label;
	}

	public function supports_chat() {
		return $this->supports_chat;
	}

	public function supports_embeddings() {
		return $this->supports_embeddings;
	}

	public function default_base_url() {
		return $this->base_url;
	}

	public function needs_base_url() {
		return $this->needs_base_url;
	}

	public function default_models() {
		return array(
			'chat'      => $this->chat_models,
			'embedding' => $this->embed_models,
		);
	}

	public function chat( array $request, array $ctx ) {
		$messages = array();
		if ( ! empty( $request['system'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => (string) $request['system'],
			);
		}
		foreach ( (array) $request['messages'] as $m ) {
			$messages[] = array(
				'role'    => $m['role'],
				'content' => $m['content'],
			);
		}

		$body = array(
			'model'       => $ctx['model'],
			'messages'    => $messages,
			'max_tokens'  => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 512,
			'temperature' => isset( $request['temperature'] ) ? (float) $request['temperature'] : 0.2,
		);
		if ( isset( $request['response_format'] ) && 'json' === $request['response_format'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$res = Http::post( $this->chat_url( $ctx ), $this->headers( $ctx ), wp_json_encode( $body ), $this->timeout( $ctx ) );
		$decoded = json_decode( (string) $res['body'], true );

		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
		}

		$text   = isset( $decoded['choices'][0]['message']['content'] ) ? (string) $decoded['choices'][0]['message']['content'] : '';
		$finish = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : 'stop';

		return array(
			'ok'            => true,
			'text'          => $text,
			'finish_reason' => $finish,
			'usage'         => array(
				'input_tokens'  => isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0,
				'output_tokens' => isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0,
			),
			'model'         => isset( $decoded['model'] ) ? (string) $decoded['model'] : $ctx['model'],
		);
	}

	public function embed( array $request, array $ctx ) {
		if ( ! $this->supports_embeddings ) {
			return array( 'ok' => false, 'error' => array( 'class' => 'unsupported', 'message' => 'Provider has no embeddings endpoint', 'is_retryable' => false, 'triggers_failover' => false, 'http_status' => 0, 'retry_after' => null ) );
		}

		$body = array(
			'model' => $ctx['model'],
			'input' => array_values( (array) $request['input'] ),
		);

		$res     = Http::post( $this->embed_url( $ctx ), $this->headers( $ctx ), wp_json_encode( $body ), $this->timeout( $ctx ) );
		$decoded = json_decode( (string) $res['body'], true );

		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			return array( 'ok' => false, 'error' => Errors::classify( $res['status'], $decoded ? $decoded : $res['body'], $res['headers'], isset( $res['error'] ) ? (string) $res['error'] : '' ) );
		}

		$vectors = array();
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			foreach ( $decoded['data'] as $row ) {
				if ( isset( $row['embedding'] ) && is_array( $row['embedding'] ) ) {
					$vectors[] = array_map( 'floatval', $row['embedding'] );
				}
			}
		}

		return array(
			'ok'      => true,
			'vectors' => $vectors,
			'usage'   => array( 'input_tokens' => isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0 ),
			'model'   => isset( $decoded['model'] ) ? (string) $decoded['model'] : $ctx['model'],
		);
	}

	/* ---- helpers (overridable) ---- */

	protected function base( array $ctx ) {
		$base = ! empty( $ctx['base_url'] ) ? $ctx['base_url'] : $this->base_url;
		return rtrim( (string) $base, '/' );
	}

	protected function chat_url( array $ctx ) {
		return $this->base( $ctx ) . '/chat/completions';
	}

	protected function embed_url( array $ctx ) {
		return $this->base( $ctx ) . '/embeddings';
	}

	protected function timeout( array $ctx ) {
		return isset( $ctx['timeout'] ) ? (int) $ctx['timeout'] : 30;
	}

	protected function headers( array $ctx ) {
		$headers = array( 'Content-Type' => 'application/json' );
		$key     = isset( $ctx['api_key'] ) ? (string) $ctx['api_key'] : '';

		if ( 'bearer' === $this->auth_style && '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		} elseif ( 'api_key_header' === $this->auth_style && '' !== $key ) {
			$headers['api-key'] = $key;
		}

		foreach ( $this->extra_headers as $k => $v ) {
			$headers[ $k ] = $v;
		}
		return $headers;
	}
}
