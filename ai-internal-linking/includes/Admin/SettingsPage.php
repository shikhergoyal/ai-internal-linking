<?php
/**
 * Advanced settings (scoring thresholds). Scope lives on the Setup page.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Settings;

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

		Settings::update(
			array(
				'min_relevance'        => $min_rel,
				'max_suggestions_post' => $per_post,
				'max_links_per_1000'   => $density,
			)
		);

		wp_safe_redirect( add_query_arg( 'ailinking_saved', '1', admin_url( 'admin.php?page=ailinking-settings' ) ) );
		exit;
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
						<th scope="row"><label for="max_links_per_1000"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="1" max="20" id="max_links_per_1000" name="max_links_per_1000" value="<?php echo esc_attr( (int) $settings['max_links_per_1000'] ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'AI provider', 'ai-internal-linking' ); ?></h2>
				<p><?php esc_html_e( 'This build runs entirely on the local, zero-cost TF-IDF engine — no API key required. Optional AI providers (multi-key pool) and the embedding re-ranker arrive in a later phase.', 'ai-internal-linking' ); ?></p>
			</div>
		</div>
		<?php
	}
}
