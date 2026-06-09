<?php
/**
 * Provider registry. Registers all built-in adapters and lets third parties add
 * their own via the `ailinking_register_providers` action — no core edits needed.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class Registry {

	/** @var array<string,callable> id => factory */
	private static $factories = array();

	/** @var array<string,ProviderInterface> id => instance (lazy) */
	private static $instances = array();

	/** @var bool */
	private static $booted = false;

	/**
	 * Register a provider factory.
	 *
	 * @param string   $id      Slug.
	 * @param callable $factory function(): ProviderInterface
	 */
	public static function register( $id, $factory ) {
		self::$factories[ $id ] = $factory;
		unset( self::$instances[ $id ] );
	}

	/**
	 * @param string $id Slug.
	 * @return bool
	 */
	public static function has( $id ) {
		self::boot();
		return isset( self::$factories[ $id ] );
	}

	/**
	 * @param string $id Slug.
	 * @return ProviderInterface|null
	 */
	public static function get( $id ) {
		self::boot();
		if ( ! isset( self::$factories[ $id ] ) ) {
			return null;
		}
		if ( ! isset( self::$instances[ $id ] ) ) {
			self::$instances[ $id ] = call_user_func( self::$factories[ $id ] );
		}
		return self::$instances[ $id ];
	}

	/**
	 * @return ProviderInterface[]
	 */
	public static function all() {
		self::boot();
		$out = array();
		foreach ( array_keys( self::$factories ) as $id ) {
			$out[ $id ] = self::get( $id );
		}
		return $out;
	}

	/**
	 * Providers supporting a capability plane.
	 *
	 * @param string $plane 'chat' | 'embedding'.
	 * @return ProviderInterface[]
	 */
	public static function for_capability( $plane ) {
		$out = array();
		foreach ( self::all() as $id => $p ) {
			if ( 'embedding' === $plane && $p->supports_embeddings() ) {
				$out[ $id ] = $p;
			} elseif ( 'chat' === $plane && $p->supports_chat() ) {
				$out[ $id ] = $p;
			}
		}
		return $out;
	}

	/**
	 * Lightweight metadata for the settings UI.
	 *
	 * @return array
	 */
	public static function meta() {
		$out = array();
		foreach ( self::all() as $id => $p ) {
			$out[ $id ] = array(
				'id'         => $id,
				'label'      => $p->label(),
				'chat'       => $p->supports_chat(),
				'embeddings' => $p->supports_embeddings(),
				'needs_base' => $p->needs_base_url(),
				'base_url'   => $p->default_base_url(),
				'models'     => $p->default_models(),
			);
		}
		return $out;
	}

	/**
	 * Register built-ins once, and fire the extension hook.
	 */
	private static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Bespoke wire.
		self::register( 'anthropic', function () { return new AnthropicProvider(); } );
		self::register( 'gemini', function () { return new GeminiProvider(); } );
		self::register( 'cohere', function () { return new CohereProvider(); } );
		self::register( 'azure', function () { return new AzureOpenAIProvider(); } );

		// OpenAI-compatible vendors.
		$oai = function ( $id, $label, $base, $opts ) {
			return function () use ( $id, $label, $base, $opts ) {
				return new OpenAICompatibleProvider( $id, $label, $base, $opts );
			};
		};

		$referer = function_exists( 'home_url' ) ? home_url() : '';
		$site    = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';

		self::register( 'openai', $oai( 'openai', 'OpenAI', 'https://api.openai.com/v1', array(
			'supports_embeddings' => true,
			'chat_models'         => array( 'gpt-4o-mini', 'gpt-4o' ),
			'embed_models'        => array( 'text-embedding-3-small', 'text-embedding-3-large' ),
		) ) );
		self::register( 'openrouter', $oai( 'openrouter', 'OpenRouter', 'https://openrouter.ai/api/v1', array(
			'supports_embeddings' => false,
			'extra_headers'       => array( 'HTTP-Referer' => $referer, 'X-Title' => $site ),
		) ) );
		self::register( 'mistral', $oai( 'mistral', 'Mistral', 'https://api.mistral.ai/v1', array(
			'supports_embeddings' => true,
			'embed_models'        => array( 'mistral-embed' ),
		) ) );
		self::register( 'groq', $oai( 'groq', 'Groq', 'https://api.groq.com/openai/v1', array( 'supports_embeddings' => false ) ) );
		self::register( 'together', $oai( 'together', 'Together AI', 'https://api.together.xyz/v1', array( 'supports_embeddings' => true ) ) );
		self::register( 'fireworks', $oai( 'fireworks', 'Fireworks AI', 'https://api.fireworks.ai/inference/v1', array( 'supports_embeddings' => true ) ) );
		self::register( 'deepseek', $oai( 'deepseek', 'DeepSeek', 'https://api.deepseek.com/v1', array( 'supports_embeddings' => false ) ) );
		self::register( 'xai', $oai( 'xai', 'xAI (Grok)', 'https://api.x.ai/v1', array( 'supports_embeddings' => false ) ) );
		self::register( 'perplexity', $oai( 'perplexity', 'Perplexity', 'https://api.perplexity.ai', array( 'supports_embeddings' => false ) ) );

		// Embeddings-only (Anthropic-recommended default).
		self::register( 'voyage', $oai( 'voyage', 'Voyage AI (embeddings)', 'https://api.voyageai.com/v1', array(
			'supports_chat'       => false,
			'supports_embeddings' => true,
			'embed_models'        => array( 'voyage-3', 'voyage-3-lite' ),
		) ) );

		// Universal: custom OpenAI-compatible endpoint + local self-hosted.
		self::register( 'custom', $oai( 'custom', 'Custom (OpenAI-compatible)', '', array(
			'supports_embeddings' => true,
			'needs_base_url'      => true,
		) ) );
		self::register( 'local', $oai( 'local', 'Local (Ollama/LM Studio/vLLM)', 'http://localhost:11434/v1', array(
			'supports_embeddings' => true,
			'needs_base_url'      => true,
			'auth_style'          => 'none',
		) ) );

		if ( function_exists( 'do_action' ) ) {
			do_action( 'ailinking_register_providers' );
		}
	}
}
