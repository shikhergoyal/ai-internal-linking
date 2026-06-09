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
		$emb_provider = isset( $_POST['embedding_provider'] ) ? sanitize_key( wp_unslash( $_POST['embedding_provider'] ) ) : 'none';
		if ( 'none' !== $emb_provider && ! Registry::has( $emb_provider ) ) {
			$emb_provider = 'none';
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
				'chat_provider'             => $chat_provider,
				'chat_model'                => isset( $_POST['chat_model'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_model'] ) ) : '',
				'embedding_provider'        => $emb_provider,
				'embedding_model'           => isset( $_POST['embedding_model'] ) ? sanitize_text_field( wp_unslash( $_POST['embedding_model'] ) ) : '',
				'reuse_chat_for_embeddings' => ! empty( $_POST['reuse_chat_for_embeddings'] ),
				'rotation'                  => $rotation,
				'monthly_cap_usd'           => $cap,
			)
		);

		wp_safe_redirect( add_query_arg( 'ailinking_saved', '1', admin_url( 'admin.php?page=ailinking-settings' ) ) );
		exit;
	}

	/**
	 * Render a provider <select> for a capability plane.
	 *
	 * @param string $name    Field name.
	 * @param string $plane   'chat'|'embedding'.
	 * @param string $current Currently selected id.
	 */
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
							<p class="description"><?php esc_html_e( 'Discard candidate links below this relevance score (0–1).', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_suggestions_post"><?php esc_html_e( 'Max suggestions per page', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="1" max="50" id="max_suggestions_post" name="max_suggestions_post" value="<?php echo esc_attr( (int) $settings['max_suggestions_post'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="min_anchor_words"><?php esc_html_e( 'Minimum anchor words', 'ai-internal-linking' ); ?></label></th>
						<td>
							<input type="number" min="1" max="4" id="min_anchor_words" name="min_anchor_words" value="<?php echo esc_attr( (int) $settings['min_anchor_words'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Prefer descriptive phrase anchors. Set to 2 to avoid single-word links; set to 1 to allow single words as a fallback.', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_anchor_words"><?php esc_html_e( 'Maximum anchor words', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="2" max="6" id="max_anchor_words" name="max_anchor_words" value="<?php echo esc_attr( (int) $settings['max_anchor_words'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="max_links_per_1000"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="1" max="20" id="max_links_per_1000" name="max_links_per_1000" value="<?php echo esc_attr( (int) $settings['max_links_per_1000'] ); ?>" /></td>
					</tr>

					<tr><th colspan="2"><h2 style="margin:0;"><?php esc_html_e( 'AI provider (optional)', 'ai-internal-linking' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Leave as “None” to run fully on the zero-cost TF-IDF engine. Add API keys under “AI Keys”. Embeddings improve relevance; Claude has no embeddings API, so pick Voyage/OpenAI (or reuse a chat provider that supports them).', 'ai-internal-linking' ); ?></p></th></tr>

					<tr>
						<th scope="row"><label for="chat_provider"><?php esc_html_e( 'Chat provider', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php $this->provider_select( 'chat_provider', 'chat', (string) $settings['chat_provider'] ); ?>
							<input type="text" name="chat_model" value="<?php echo esc_attr( (string) $settings['chat_model'] ); ?>" placeholder="<?php esc_attr_e( 'model id (optional)', 'ai-internal-linking' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="embedding_provider"><?php esc_html_e( 'Embeddings provider', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php $this->provider_select( 'embedding_provider', 'embedding', (string) $settings['embedding_provider'] ); ?>
							<input type="text" name="embedding_model" value="<?php echo esc_attr( (string) $settings['embedding_model'] ); ?>" placeholder="<?php esc_attr_e( 'model id (optional)', 'ai-internal-linking' ); ?>" />
							<p class="description">
								<label><input type="checkbox" name="reuse_chat_for_embeddings" value="1" <?php checked( ! empty( $settings['reuse_chat_for_embeddings'] ) ); ?> /> <?php esc_html_e( 'Reuse the chat provider for embeddings when it supports them', 'ai-internal-linking' ); ?></label>
							</p>
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
