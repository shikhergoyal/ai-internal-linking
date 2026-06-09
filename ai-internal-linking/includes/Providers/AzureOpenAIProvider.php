<?php
/**
 * Azure OpenAI adapter. OpenAI wire, but URLs are deployment-based with an
 * api-version query param and an api-key header. The key record stores the
 * resource endpoint as base_url, the deployment name as model, and (optionally)
 * api_version in extra_config. Best-effort; verify against a live deployment.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class AzureOpenAIProvider extends OpenAICompatibleProvider {

	const DEFAULT_API_VERSION = '2024-02-15-preview';

	public function __construct() {
		parent::__construct(
			'azure',
			'Azure OpenAI',
			'',
			array(
				'supports_embeddings' => true,
				'needs_base_url'      => true,
				'auth_style'          => 'api_key_header',
				'chat_models'         => array(),
				'embed_models'        => array(),
			)
		);
	}

	protected function chat_url( array $ctx ) {
		return $this->base( $ctx ) . '/openai/deployments/' . rawurlencode( $ctx['model'] ) . '/chat/completions?api-version=' . rawurlencode( $this->api_version( $ctx ) );
	}

	protected function embed_url( array $ctx ) {
		return $this->base( $ctx ) . '/openai/deployments/' . rawurlencode( $ctx['model'] ) . '/embeddings?api-version=' . rawurlencode( $this->api_version( $ctx ) );
	}

	private function api_version( array $ctx ) {
		if ( ! empty( $ctx['extra']['api_version'] ) ) {
			return (string) $ctx['extra']['api_version'];
		}
		return self::DEFAULT_API_VERSION;
	}
}
