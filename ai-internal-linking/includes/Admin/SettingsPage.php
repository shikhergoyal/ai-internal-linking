<?php
/**
 * Advanced settings (scoring thresholds). Scope lives on the Setup page.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Settings;
use AILinking\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	/**
	 * Register form handler.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_save_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Persist settings.
	 */
	public function handle_save() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_save_settings' );

		$min_rel  = isset( $_POST['min_relevance'] ) ? (float) $_POST['min_relevance'] : 0.08;
		$min_rel  = max( 0.0, min( 1.0, $min_rel ) );

		$per_post = isset( $_POST['max_suggestions_post'] ) ? (int) $_POST['max_suggestions_post'] : 8;
		$per_post = max( 1, min( 50, $per_post ) );

		$density  = isset( $_POST['max_links_per_1000'] ) ? (int) $_POST['max_links_per_1000'] : 5;
		$density  = max( 1, min( 20, $density ) );

		$min_words = isset( $_POST['min_anchor_words'] ) ? (int) $_POST['min_anchor_words'] : 2;
		$min_words = max( 1, min( 4, $min_words ) );

		$max_words = isset( $_POST['max_anchor_words'] ) ? (int) $_POST['max_anchor_words'] : 4;
		$max_words = max( $min_words, min( 6, $max_words ) );

		// --- AI provider configuration ---
		$chat_provider = isset( $_POST['chat_provider'] ) ? sanitize_key( wp_unslash( $_POST['chat_provider'] ) ) : 'none';
		if ( 'none' !== $chat_provider && ! Registry::has( $chat_provider ) ) {
			$chat_provider = 'none';
		}
		$rotation = isset( $_POST['rotation'] ) ? sanitize_key( wp_unslash( $_POST['rotation'] ) ) : 'round_robin';
		if ( ! in_array( $rotation, array( 'round_robin', 'primary_failover' ), true ) ) {
			$rotation = 'round_robin';
		}
		$cap = isset( $_POST['monthly_cap_usd'] ) ? (float) $_POST['monthly_cap_usd'] : 0;
		$cap = max( 0, $cap );

		Settings::update(
			array(
				'min_relevance'             => $min_rel,
				'max_suggestions_post'      => $per_post,
				'max_links_per_1000'        => $density,
				'min_anchor_words'          => $min_words,
				'max_anchor_words'          => $max_words,
				'keyword_suggestions'       => ! empty( $_POST['keyword_suggestions'] ),
				'llm_suggestions'           => ! empty( $_POST['llm_suggestions'] ),
				'chat_provider'             => $chat_provider,
				'chat_model'                => Registry::resolve_model_choice(
					isset( $_POST['chat_model'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_model'] ) ) : '',
					isset( $_POST['chat_model_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_model_custom'] ) ) : ''
				),
				'rotation'                  => $rotation,
				'monthly_cap_usd'           => $cap,
			)
		);

		wp_safe_redirect( add_query_arg( 'ailinking_saved', '1', admin_url( 'admin.php?page=ailinking&tab=settings' ) ) );
		exit;
	}

	/**
	 * Render a provider <select> for a capability plane.
	 *
	 * @param string $name    Field name.
	 * @param string $plane   Capability plane ('chat').
	 * @param string $current Currently selected id.
	 */
	/**
	 * A model picker: the known ids for the chosen provider, plus "Other model…".
	 *
	 * The options are rebuilt in JS when the paired provider select changes, so
	 * the list always belongs to the provider actually selected.
	 *
	 * @param string $name          Field name for the select ("<name>_custom" carries a typed id).
	 * @param string $provider_name Field name of the provider select to follow.
	 * @param string $provider      Currently selected provider id.
	 * @param string $current       Currently stored model id.
	 * @return void
	 */
	private function model_select( $name, $provider_name, $provider, $current ) {
		$map    = Registry::chat_models_map();
		$models = isset( $map[ $provider ] ) ? $map[ $provider ] : array();
		$custom = '' !== $current && ! in_array( $current, $models, true );

		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '"'
			. ' class="ailinking-model-select" data-follows="' . esc_attr( $provider_name ) . '"'
			. ' data-selected="' . esc_attr( $current ) . '">';
		echo '<option value="">' . esc_html__( 'Provider default', 'ai-internal-linking' ) . '</option>';
		foreach ( $models as $m ) {
			echo '<option value="' . esc_attr( $m ) . '"' . selected( $m, $current, false ) . '>' . esc_html( $m ) . '</option>';
		}
		echo '<option value="__custom"' . selected( true, $custom, false ) . '>' . esc_html__( 'Other model…', 'ai-internal-linking' ) . '</option>';
		echo '</select> ';

		echo '<input type="text" class="ailinking-model-custom regular-text" name="' . esc_attr( $name ) . '_custom"'
			. ' value="' . esc_attr( $custom ? $current : '' ) . '"'
			. ' placeholder="' . esc_attr__( 'exact model id', 'ai-internal-linking' ) . '"'
			. ' style="display:' . ( $custom ? 'inline-block' : 'none' ) . ';" />';
	}

	private function provider_select( $name, $plane, $current ) {
		$providers = Registry::for_capability( $plane );
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="none"' . selected( 'none', $current, false ) . '>' . esc_html__( 'None (TF-IDF)', 'ai-internal-linking' ) . '</option>';
		foreach ( $providers as $id => $p ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $id, $current, false ) . '>' . esc_html( $p->label() ) . '</option>';
		}
		echo '</select> ';
	}

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();
		$settings = Settings::all();
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Settings', 'ai-internal-linking' ); ?></h1>

			<?php if ( isset( $_GET['ailinking_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ai-internal-linking' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ailinking-card">
				<input type="hidden" name="action" value="ailinking_save_settings" />
				<?php wp_nonce_field( 'ailinking_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="min_relevance"><?php esc_html_e( 'Minimum relevance', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" max="1" id="min_relevance" name="min_relevance" value="<?php echo esc_attr( $settings['min_relevance'] ); ?>" />
						<p class="description">
							<strong><?php esc_html_e( 'What relevance means.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'A number from 0 to 1 answering one question: do these two pages belong together? It is not a measure of how well the link reads — that is naturalness, and the two are combined into the percentage shown in the review screen.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'How each engine works it out.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'Related Content compares the two pages word by word, and the score is the share of this page’s distinctive vocabulary that the other page also uses. AI Suggestion uses the model’s own stated confidence in the pick. GSC keyword starts at 0.50 because a phrase people actually search for is stronger evidence than any word overlap, then adds more for striking-distance rankings.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'What this setting does.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'It sets the floor for the Related Content engine only. Below it, two pages share so little vocabulary that the overlap is coincidence. It does not filter AI or Search Console suggestions, which are judged on different evidence and are not comparable to a word-overlap score.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Choosing a number.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'The default of 0.08 is deliberately low, because the anchor still has to be found in your text before anything is suggested, and that rejects far more than this does. Raise it to 0.15 or 0.20 if the Related Content suggestions you reject feel only loosely related.', 'ai-internal-linking' ); ?>
						</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_suggestions_post"><?php esc_html_e( 'Max suggestions per page', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" min="1" max="50" id="max_suggestions_post" name="max_suggestions_post" value="<?php echo esc_attr( (int) $settings['max_suggestions_post'] ); ?>" />
						<p class="description">
							<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'The most new suggestions any one page can produce in a single scan, across all three engines together.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Why a limit.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'It keeps a review session finishable. The link limit below is the real constraint on your content; this one is about how much you are asked to judge at once.', 'ai-internal-linking' ); ?>
						</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="min_anchor_words"><?php esc_html_e( 'Minimum anchor words', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" min="1" max="4" id="min_anchor_words" name="min_anchor_words" value="<?php echo esc_attr( (int) $settings['min_anchor_words'] ); ?>" />
						<p class="description">
							<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'The shortest phrase allowed to become a link.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Why 2.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'A one-word link tells a reader almost nothing about where it goes, and search engines read the linked words as a description of the destination. Set it to 1 only if you would rather have a single-word link than none at all.', 'ai-internal-linking' ); ?>
						</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_anchor_words"><?php esc_html_e( 'Maximum anchor words', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" min="2" max="6" id="max_anchor_words" name="max_anchor_words" value="<?php echo esc_attr( (int) $settings['max_anchor_words'] ); ?>" />
						<p class="description">
							<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'The longest phrase allowed to become a link. Past four or five words a link starts to swallow the sentence around it, which reads badly and dilutes what the link is about.', 'ai-internal-linking' ); ?>
						</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_links_per_1000"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" min="1" max="20" id="max_links_per_1000" name="max_links_per_1000" value="<?php echo esc_attr( (int) $settings['max_links_per_1000'] ); ?>" />
						<p class="description">
							<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'Caps how densely a page may be linked, counting links already in your content as well as new ones. A page that has reached its limit is skipped before any engine is asked about it.', 'ai-internal-linking' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Why it matters.', 'ai-internal-linking' ); ?></strong>
							<?php esc_html_e( 'Every page gets at least two regardless of length, so short pages are not shut out.', 'ai-internal-linking' ); ?>
						</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Keyword suggestions', 'ai-internal-linking' ); ?></th>
						<td>
							<label><input type="checkbox" name="keyword_suggestions" value="1" <?php checked( ! empty( $settings['keyword_suggestions'] ) ); ?> /> <?php esc_html_e( 'Use imported Google Search Console keywords as a suggestion source', 'ai-internal-linking' ); ?></label>
							<p class="description"><?php esc_html_e( 'When a post mentions a query that another page already ranks for — without linking to it — that mention becomes a high-priority suggestion with the keyword as the anchor. Connect Google Search Console under the Connect GSC tab; striking-distance keywords (positions 5–20) are favoured.', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>

					<tr><th colspan="2"><h2 style="margin:0;"><?php esc_html_e( 'AI provider (optional)', 'ai-internal-linking' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Leave as “None” to run fully on the zero-cost engines. Add API keys under “AI Keys”.', 'ai-internal-linking' ); ?></p></th></tr>

					<tr>
						<th scope="row"><label for="chat_provider"><?php esc_html_e( 'Chat provider', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php
							$this->provider_select( 'chat_provider', 'chat', (string) $settings['chat_provider'] );
							$this->model_select( 'chat_model', 'chat_provider', (string) $settings['chat_provider'], (string) $settings['chat_model'] );
							?>
							<p class="description">
								<?php esc_html_e( 'Leave the model blank to use the provider\'s current default. A model this plugin does not list still works — choose “Other model…” and type its id — but a typo there comes back as a plain error from the provider that looks like a rejected key, so prefer the list.', 'ai-internal-linking' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI link suggestions', 'ai-internal-linking' ); ?></th>
						<td>
							<label><input type="checkbox" name="llm_suggestions" value="1" <?php checked( ! empty( $settings['llm_suggestions'] ) ); ?> /> <?php esc_html_e( 'Let the chat model propose contextual links', 'ai-internal-linking' ); ?></label>
							<p class="description"><?php esc_html_e( 'When on, each suggestion scan asks your chat provider to pick the best links for every page from a shortlist of genuinely related pages, choosing a natural anchor already in the text. Works with any chat key (OpenAI, Claude, Gemini, Groq, local, and more). Targets and anchors are verified against real content, so nothing is fabricated; picks carry an “AI” badge in the inbox. Uses your monthly spend cap, and falls back to the free engine if the model is unavailable.', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rotation"><?php esc_html_e( 'Key rotation', 'ai-internal-linking' ); ?></label></th>
						<td>
							<select name="rotation" id="rotation">
								<option value="round_robin" <?php selected( 'round_robin', $settings['rotation'] ); ?>><?php esc_html_e( 'Round-robin', 'ai-internal-linking' ); ?></option>
								<option value="primary_failover" <?php selected( 'primary_failover', $settings['rotation'] ); ?>><?php esc_html_e( 'Primary with failover', 'ai-internal-linking' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="monthly_cap_usd"><?php esc_html_e( 'Monthly spend cap (USD)', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" id="monthly_cap_usd" name="monthly_cap_usd" value="<?php echo esc_attr( (float) $settings['monthly_cap_usd'] ); ?>" />
							<p class="description"><?php esc_html_e( '0 = no cap. When reached, AI auto-pauses and the engine falls back to TF-IDF. Costs are estimates.', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
