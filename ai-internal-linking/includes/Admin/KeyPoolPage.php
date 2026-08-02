<?php
/**
 * AI Keys page: manage the encrypted multi-key pool, view per-key health/spend,
 * and test connections.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Security\Crypto;
use AILinking\Providers\Registry;
use AILinking\Providers\KeyPoolManager;
use AILinking\Providers\UsageStats;

defined( 'ABSPATH' ) || exit;

class KeyPoolPage {

	/**
	 * Register form handlers.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_add_key', array( $this, 'handle_add' ) );
		add_action( 'admin_post_ailinking_delete_key', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ailinking_toggle_key', array( $this, 'handle_toggle' ) );
	}

	public function handle_add() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_add_key' );

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! Registry::has( $provider ) ) {
			$this->redirect( 'bad_provider' );
		}

		$extra = array();
		if ( ! empty( $_POST['api_version'] ) ) {
			$extra['api_version'] = sanitize_text_field( wp_unslash( $_POST['api_version'] ) );
		}

		$id = KeyPoolManager::add_key(
			array(
				'provider'   => $provider,
				'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'api_key'    => isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '',
				'base_url'   => isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '',
				'model'      => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
				'capability' => self::clean_capability(),
				'extra'      => $extra,
				'priority'   => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 100,
			)
		);

		$this->redirect( $id ? 'added' : 'add_failed' );
	}

	/**
	 * Sanitise + validate the capability field.
	 *
	 * @return string
	 */
	private static function clean_capability() {
		// Chat is the only plane; the embeddings plane was removed in 0.14.0.
		return 'chat';
	}

	public function handle_delete() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_delete_key' );
		KeyPoolManager::delete_key( isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0 );
		$this->redirect( 'deleted' );
	}

	public function handle_toggle() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_toggle_key' );
		$id      = isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0;
		$enabled = ! empty( $_POST['enabled'] );
		KeyPoolManager::set_enabled( $id, $enabled );
		$this->redirect( 'updated' );
	}

	private function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( 'ailinking_msg', $msg, admin_url( 'admin.php?page=ailinking&tab=keys' ) ) );
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();
		$health   = KeyPoolManager::health();
		$meta     = Registry::meta();
		$by_key   = UsageStats::by_key( 'month' );
		$mtd      = UsageStats::totals( 'month' );
		$all_time = UsageStats::totals( 'all' );
		$rows     = UsageStats::breakdown( 'all' );
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — AI Keys', 'ai-internal-linking' ); ?></h1>

			<?php if ( ! Crypto::available() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Encryption is unavailable or the site salts changed since keys were saved. Saved keys cannot be decrypted — please re-enter them.', 'ai-internal-linking' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ailinking_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ailinking_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'Key pool', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Chat keys power the optional "AI link suggestions" engine. The free engines need no key at all.', 'ai-internal-linking' ); ?></p>

				<table class="wp-list-table widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Provider', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Label', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Key', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Plane', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'State', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Tokens in (mo)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Tokens out (mo)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Spend (mo)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ai-internal-linking' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $health ) ) : ?>
							<tr><td colspan="11"><?php esc_html_e( 'No keys yet. Add one below.', 'ai-internal-linking' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $health as $k ) : ?>
								<?php $u = isset( $by_key[ (int) $k['id'] ] ) ? $by_key[ (int) $k['id'] ] : array( 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0 ); ?>
								<tr>
									<td><?php echo esc_html( $k['provider'] ); ?></td>
									<td><?php echo esc_html( $k['label'] ); ?></td>
									<td><code><?php echo esc_html( $k['key_last4'] ? '…' . $k['key_last4'] : '—' ); ?></code></td>
									<td><?php
										// Embedding-only keys are inert since 0.14.0; say so rather
										// than showing a plane that no longer exists.
										echo 'embedding' === $k['capability']
											? esc_html__( 'unused (embeddings removed)', 'ai-internal-linking' )
											: esc_html( $k['capability'] );
									?></td>
									<td><?php echo esc_html( $k['enabled'] ? $k['state'] : 'disabled' ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $k['request_count'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $k['error_count'] ) ); ?></td>
									<td title="<?php echo esc_attr( number_format_i18n( $u['tokens_in'] ) ); ?>"><?php echo esc_html( UsageStats::format_tokens( $u['tokens_in'] ) ); ?></td>
									<td title="<?php echo esc_attr( number_format_i18n( $u['tokens_out'] ) ); ?>"><?php echo esc_html( UsageStats::format_tokens( $u['tokens_out'] ) ); ?></td>
									<td><?php echo esc_html( UsageStats::format_cost( $u['cost'] ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
											<input type="hidden" name="action" value="ailinking_toggle_key" />
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>" />
											<input type="hidden" name="enabled" value="<?php echo $k['enabled'] ? '0' : '1'; ?>" />
											<?php wp_nonce_field( 'ailinking_toggle_key' ); ?>
											<button class="button button-small"><?php echo $k['enabled'] ? esc_html__( 'Disable', 'ai-internal-linking' ) : esc_html__( 'Enable', 'ai-internal-linking' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this key?', 'ai-internal-linking' ) ); ?>');">
											<input type="hidden" name="action" value="ailinking_delete_key" />
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>" />
											<?php wp_nonce_field( 'ailinking_delete_key' ); ?>
											<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'ai-internal-linking' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'Token usage', 'ai-internal-linking' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Every provider call is logged with the token counts the provider itself reported. Cost is an estimate from published list prices, so treat it as a close guide rather than an invoice.', 'ai-internal-linking' ); ?>
				</p>

				<div class="ailinking-usage-totals">
					<?php
					$blocks = array(
						array( __( 'This month', 'ai-internal-linking' ), $mtd ),
						array( __( 'All time', 'ai-internal-linking' ), $all_time ),
					);
					foreach ( $blocks as $block ) :
						list( $heading, $t ) = $block;
						?>
						<div class="ailinking-usage-block">
							<h3><?php echo esc_html( $heading ); ?></h3>
							<p class="ailinking-stat">
								<span class="ailinking-num"><?php echo esc_html( UsageStats::format_cost( $t['cost'] ) ); ?></span>
								<?php esc_html_e( 'estimated', 'ai-internal-linking' ); ?>
							</p>
							<ul class="ailinking-usage-list">
								<li><?php echo esc_html( sprintf( /* translators: %s: token count */ __( '%s input tokens', 'ai-internal-linking' ), UsageStats::format_tokens( $t['tokens_in'] ) ) ); ?></li>
								<li><?php echo esc_html( sprintf( /* translators: %s: token count */ __( '%s output tokens', 'ai-internal-linking' ), UsageStats::format_tokens( $t['tokens_out'] ) ) ); ?></li>
								<li><?php echo esc_html( sprintf( /* translators: %s: request count */ __( '%s requests', 'ai-internal-linking' ), number_format_i18n( $t['requests'] ) ) ); ?></li>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>

				<h3><?php esc_html_e( 'By model (all time)', 'ai-internal-linking' ); ?></h3>
				<table class="wp-list-table widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Provider', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Model', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Operation', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Tokens in', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Tokens out', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Est. cost', 'ai-internal-linking' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No AI calls yet. The free engine does not use tokens.', 'ai-internal-linking' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['provider'] ); ?></td>
									<td><code><?php echo esc_html( '' !== $r['model'] ? $r['model'] : '—' ); ?></code></td>
									<td><?php echo esc_html( $r['operation'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $r['requests'] ) ); ?></td>
									<td title="<?php echo esc_attr( number_format_i18n( $r['tokens_in'] ) ); ?>"><?php echo esc_html( UsageStats::format_tokens( $r['tokens_in'] ) ); ?></td>
									<td title="<?php echo esc_attr( number_format_i18n( $r['tokens_out'] ) ); ?>"><?php echo esc_html( UsageStats::format_tokens( $r['tokens_out'] ) ); ?></td>
									<td><?php echo esc_html( UsageStats::format_cost( $r['cost'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ailinking-card">
				<input type="hidden" name="action" value="ailinking_add_key" />
				<?php wp_nonce_field( 'ailinking_add_key' ); ?>
				<h2><?php esc_html_e( 'Add a key', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Add keys you own. Multiple keys spread load and provide failover. Keys are encrypted at rest and masked here.', 'ai-internal-linking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="provider"><?php esc_html_e( 'Provider', 'ai-internal-linking' ); ?></label></th>
						<td>
							<select name="provider" id="provider">
								<?php foreach ( $meta as $id => $m ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $m['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="label"><?php esc_html_e( 'Label', 'ai-internal-linking' ); ?></label></th>
						<td><input type="text" name="label" id="label" placeholder="<?php esc_attr_e( 'e.g. OpenAI #1', 'ai-internal-linking' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key"><?php esc_html_e( 'API key', 'ai-internal-linking' ); ?></label></th>
						<td><input type="password" name="api_key" id="api_key" class="regular-text" autocomplete="off" /> <span class="description"><?php esc_html_e( '(leave blank for local/no-auth endpoints)', 'ai-internal-linking' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="model"><?php esc_html_e( 'Model', 'ai-internal-linking' ); ?></label></th>
						<td><input type="text" name="model" id="model" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. gpt-4o-mini / claude-sonnet-4-5', 'ai-internal-linking' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="base_url"><?php esc_html_e( 'Base URL', 'ai-internal-linking' ); ?></label></th>
						<td><input type="url" name="base_url" id="base_url" class="regular-text" placeholder="<?php esc_attr_e( 'only for Custom / Local / Azure', 'ai-internal-linking' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="api_version"><?php esc_html_e( 'Azure api-version', 'ai-internal-linking' ); ?></label></th>
						<td><input type="text" name="api_version" id="api_version" placeholder="2024-02-15-preview" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="priority"><?php esc_html_e( 'Priority', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" name="priority" id="priority" value="100" /> <span class="description"><?php esc_html_e( 'Lower = preferred (primary-failover mode).', 'ai-internal-linking' ); ?></span></td>
					</tr>
				</table>
				<p>
					<button type="button" class="button" id="ailinking-test-conn"><?php esc_html_e( 'Test connection', 'ai-internal-linking' ); ?></button>
					<span id="ailinking-test-result" class="description"></span>
				</p>
				<?php submit_button( __( 'Add key', 'ai-internal-linking' ) ); ?>
			</form>
		</div>
		<?php
	}
}
