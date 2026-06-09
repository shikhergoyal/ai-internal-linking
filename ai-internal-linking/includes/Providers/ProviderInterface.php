<?php
/**
 * Contract every AI provider adapter implements. Adapters are stateless
 * translators: normalized request -> wire format -> normalized response. The key
 * and endpoint are injected per call via $ctx; adapters hold no pool/secret state.
 *
 * Normalized chat request:
 *   [ system?:string, messages:[[role,content]], max_tokens:int, temperature:float,
 *     response_format:'text'|'json', purpose?:string ]
 * Normalized chat response (ok):
 *   [ ok:true, text:string, finish_reason:string, usage:[input_tokens,output_tokens], model:string ]
 * Embed request: [ input:string[] ]   Embed response (ok): [ ok:true, vectors:float[][], usage:[input_tokens], model ]
 * Error (either): [ ok:false, error:<see Errors::classify> ]
 *
 * $ctx: [ api_key:string, base_url:string, model:string, timeout:int, extra:array ]
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/** @return string Stable slug, e.g. 'openai'. */
	public function id();

	/** @return string Human label. */
	public function label();

	/** @return bool */
	public function supports_chat();

	/** @return bool */
	public function supports_embeddings();

	/** @return string Default API base URL (may be '' for user-supplied). */
	public function default_base_url();

	/** @return bool Whether this provider needs the user to supply a base URL. */
	public function needs_base_url();

	/**
	 * Suggested model ids: [ 'chat'=>[ids...], 'embedding'=>[ids...] ].
	 *
	 * @return array
	 */
	public function default_models();

	/**
	 * @param array $request Normalized chat request.
	 * @param array $ctx     Provider context.
	 * @return array Normalized chat response or error.
	 */
	public function chat( array $request, array $ctx );

	/**
	 * @param array $request Embed request ([input=>string[]]).
	 * @param array $ctx     Provider context.
	 * @return array Embed response or error.
	 */
	public function embed( array $request, array $ctx );
}
