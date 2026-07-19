<?php
/**
 * Connect-GSC page: fetch/import Search Console keywords and view striking-distance
 * opportunities (positions 5-20) mapped to posts.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Security\Crypto;
use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Integrations\KeywordImporter;
use AILinking\Integrations\GoogleServiceAccount;
use AILinking\Integrations\SearchConsoleClient;

defined( 'ABSPATH' ) || exit;

class KeywordsPage {

	/**
	 * Register form handlers.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_import_keywords', array( $this, 'handle_import' ) );
		add_action( 'admin_post_ailinking_gsc_connect', array( $this, 'handle_gsc_connect' ) );
		add_action( 'admin_post_ailinking_gsc_verify', array( $this, 'handle_gsc_verify' ) );
		add_action( 'admin_post_ailinking_gsc_save', array( $this, 'handle_gsc_save' ) );
		add_action( 'admin_post_ailinking_gsc_disconnect', array( $this, 'handle_gsc_disconnect' ) );
	}

	/**
	 * Store a short-lived admin notice for the GSC section.
	 *
	 * @param string $type 'success'|'warning'|'error'.
	 * @param string $text Message.
	 */
	private function gsc_notice( $type, $text ) {
		set_transient( 'ailinking_gsc_notice', array( 'type' => $type, 'text' => $text ), 30 );
	}

	/**
	 * Redirect back to the Keywords tab.
	 */
	private function redirect_gsc() {
		wp_safe_redirect( admin_url( 'admin.php?page=ailinking&tab=keywords' ) );
		exit;
	}

	/**
	 * Save the pasted/uploaded service-account JSON, then try to list properties.
	 */
	public function handle_gsc_connect() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_gsc_connect' );

		$json = '';
		if ( ! empty( $_FILES['gsc_file']['tmp_name'] ) && is_uploaded_file( $_FILES['gsc_file']['tmp_name'] ) ) {
			$json = (string) file_get_contents( $_FILES['gsc_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( ! empty( $_POST['gsc_json'] ) ) {
			$json = trim( (string) wp_unslash( $_POST['gsc_json'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		if ( '' === $json ) {
			$this->gsc_notice( 'error', __( 'Paste the JSON key or choose the file first.', 'ai-internal-linking' ) );
			$this->redirect_gsc();
		}

		$res = GoogleServiceAccount::save_credentials( $json );
		if ( empty( $res['ok'] ) ) {
			$reason = isset( $res['error'] ) ? $res['error'] : 'failed';
			$this->gsc_notice( 'error', $this->connect_error_message( $reason ) );
			$this->redirect_gsc();
		}

		$this->refresh_sites();
		$this->redirect_gsc();
	}

	/**
	 * Map a save_credentials() error code to a human message.
	 *
	 * @param string $reason Error code.
	 * @return string
	 */
	private function connect_error_message( $reason ) {
		$map = array(
			'invalid_json'        => __( 'That does not look like valid JSON.', 'ai-internal-linking' ),
			'not_service_account' => __( 'That JSON is not a service-account key (its "type" must be "service_account").', 'ai-internal-linking' ),
			'crypto_unavailable'  => __( 'Secure storage is unavailable on this server (no Sodium/OpenSSL), so the key cannot be stored safely.', 'ai-internal-linking' ),
			'encrypt_failed'      => __( 'Could not encrypt the key for storage.', 'ai-internal-linking' ),
		);
		if ( isset( $map[ $reason ] ) ) {
			return $map[ $reason ];
		}
		if ( 0 === strpos( (string) $reason, 'missing_' ) ) {
			/* translators: %s: JSON field name */
			return sprintf( __( 'The key is missing the "%s" field.', 'ai-internal-linking' ), substr( $reason, 8 ) );
		}
		return __( 'Could not save the key.', 'ai-internal-linking' );
	}

	/**
	 * Re-list Search Console properties and store them for the picker.
	 */
	private function refresh_sites() {
		$list = SearchConsoleClient::list_sites();
		if ( empty( $list['ok'] ) ) {
			update_option( 'ailinking_gsc_sites', array(), false );
			$msg = isset( $list['error']['message'] ) ? $list['error']['message'] : __( 'Could not list properties.', 'ai-internal-linking' );
			$this->gsc_notice( 'warning', $msg . ' ' . __( 'Add the service-account email as a user in Search Console, then click “Re-check properties”.', 'ai-internal-linking' ) );
			return;
		}

		update_option( 'ailinking_gsc_sites', $list['sites'], false );
		$n = count( $list['sites'] );
		if ( 0 === $n ) {
			$this->gsc_notice( 'warning', __( 'Connected, but this service account cannot see any properties yet. Add its email as a user in Search Console, then click “Re-check properties”.', 'ai-internal-linking' ) );
			return;
		}
		/* translators: %d: number of properties */
		$this->gsc_notice( 'success', sprintf( _n( 'Connected — %d property available. Pick one below and save.', 'Connected — %d properties available. Pick one below and save.', $n, 'ai-internal-linking' ), $n ) );
	}

	/**
	 * Re-check available properties on demand.
	 */
	public function handle_gsc_verify() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_gsc_verify' );
		$this->refresh_sites();
		$this->redirect_gsc();
	}

	/**
	 * Save the selected property, window, and auto-sync toggle.
	 */
	public function handle_gsc_save() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_gsc_save' );

		$raw   = isset( $_POST['gsc_property'] ) ? trim( (string) wp_unslash( $_POST['gsc_property'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$sites = (array) get_option( 'ailinking_gsc_sites', array() );
		$property = '';
		foreach ( $sites as $s ) {
			if ( isset( $s['siteUrl'] ) && $s['siteUrl'] === $raw ) {
				$property = (string) $s['siteUrl'];
				break;
			}
		}
		if ( '' === $property ) {
			// Keep the existing selection if the posted value isn't in the known list.
			$property = empty( $sites ) ? sanitize_text_field( $raw ) : (string) Settings::get( 'gsc_property', '' );
		}

		$range   = isset( $_POST['gsc_range_days'] ) ? (int) $_POST['gsc_range_days'] : 90;
		$allowed = array( 28, 90, 180, 365, 480 );
		if ( ! in_array( $range, $allowed, true ) ) {
			$range = 90;
		}
		$auto = ! empty( $_POST['gsc_auto_sync'] );

		Settings::update(
			array(
				'gsc_property'   => $property,
				'gsc_range_days' => $range,
				'gsc_auto_sync'  => $auto,
			)
		);
		$this->reschedule_cron( $auto );

		$this->gsc_notice( 'success', __( 'Search Console settings saved.', 'ai-internal-linking' ) );
		$this->redirect_gsc();
	}

	/**
	 * Forget the connection.
	 */
	public function handle_gsc_disconnect() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_gsc_disconnect' );
		GoogleServiceAccount::disconnect();
		wp_clear_scheduled_hook( 'ailinking_cron_gsc' );
		$this->gsc_notice( 'success', __( 'Disconnected from Search Console. Imported keywords were kept.', 'ai-internal-linking' ) );
		$this->redirect_gsc();
	}

	/**
	 * Schedule or clear the daily auto-sync event.
	 *
	 * @param bool $on Whether auto-sync is enabled.
	 */
	private function reschedule_cron( $on ) {
		wp_clear_scheduled_hook( 'ailinking_cron_gsc' );
		if ( $on && ! wp_next_scheduled( 'ailinking_cron_gsc' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'ailinking_cron_gsc' );
		}
	}

	/**
	 * Handle CSV upload.
	 */
	public function handle_import() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_import_keywords' );

		$source = isset( $_POST['source'] ) && in_array( $_POST['source'], array( 'gsc', 'csv' ), true )
			? sanitize_key( wp_unslash( $_POST['source'] ) )
			: 'csv';

		if ( empty( $_FILES['ailinking_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['ailinking_csv']['tmp_name'] ) ) {
			$this->redirect( 'no_file' );
		}
		$tmp = $_FILES['ailinking_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$result = KeywordImporter::import_csv( $tmp, $source );

		if ( empty( $result['ok'] ) ) {
			$this->redirect( 'import_' . ( isset( $result['reason'] ) ? $result['reason'] : 'failed' ) );
		}
		$this->redirect( 'imported_' . (int) $result['imported'] );
	}

	private function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( 'ailinking_msg', $msg, admin_url( 'admin.php?page=ailinking&tab=keywords' ) ) );
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();
		global $wpdb;
		$table = Tables::keywords();

		$striking = $wpdb->get_results(
			"SELECT keyword, impressions, position, clicks, post_id, opportunity_score
			 FROM {$table} WHERE is_striking = 1 ORDER BY opportunity_score DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Connect GSC', 'ai-internal-linking' ); ?></h1>

			<?php if ( isset( $_GET['ailinking_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ailinking_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<?php $this->render_gsc_card(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ailinking-card">
				<input type="hidden" name="action" value="ailinking_import_keywords" />
				<?php wp_nonce_field( 'ailinking_import_keywords' ); ?>
				<h2><?php esc_html_e( 'Import keyword CSV', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Manual fallback to the API fetch above: upload a Google Search Console performance export, or any CSV with Query/Keyword, Clicks, Impressions, CTR, Position (and optionally Page/URL).', 'ai-internal-linking' ); ?></p>
				<p class="description"><?php esc_html_e( 'Keywords with a mapped page feed the suggestion engine: when another post mentions one of these queries without linking to its page, the next suggestion scan proposes that link with the keyword as the anchor (badge: “GSC keyword”). Include the Page/URL column so keywords can be mapped.', 'ai-internal-linking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="source"><?php esc_html_e( 'Source', 'ai-internal-linking' ); ?></label></th>
						<td>
							<select name="source" id="source">
								<option value="gsc"><?php esc_html_e( 'Google Search Console', 'ai-internal-linking' ); ?></option>
								<option value="csv"><?php esc_html_e( 'Generic CSV', 'ai-internal-linking' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Re-importing replaces previous rows from the same source.', 'ai-internal-linking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ailinking_csv"><?php esc_html_e( 'CSV file', 'ai-internal-linking' ); ?></label></th>
						<td><input type="file" name="ailinking_csv" id="ailinking_csv" accept=".csv,text/csv" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Import', 'ai-internal-linking' ) ); ?>
			</form>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'Striking-distance keywords (positions 5–20)', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s total */ __( '%s keywords imported.', 'ai-internal-linking' ), number_format_i18n( $total ) ) ); ?></p>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th><?php esc_html_e( 'Keyword', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Impressions', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Position', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Mapped page', 'ai-internal-linking' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $striking ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No striking-distance keywords yet. Import a CSV above.', 'ai-internal-linking' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $striking as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['keyword'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['impressions'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (float) $row['position'], 1 ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['clicks'] ) ); ?></td>
									<td><?php echo $row['post_id'] ? esc_html( get_the_title( (int) $row['post_id'] ) ) : '<span class="description">—</span>'; ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Fetch from Google Search Console (API)" card.
	 */
	private function render_gsc_card() {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );

		$notice = get_transient( 'ailinking_gsc_notice' );
		if ( is_array( $notice ) ) {
			delete_transient( 'ailinking_gsc_notice' );
			$type  = isset( $notice['type'] ) ? $notice['type'] : 'info';
			$class = 'success' === $type ? 'notice-success' : ( 'error' === $type ? 'notice-error' : 'notice-warning' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( isset( $notice['text'] ) ? $notice['text'] : '' ) . '</p></div>';
		}
		?>
		<div class="ailinking-card">
			<h2><?php esc_html_e( 'Fetch from Google Search Console (API)', 'ai-internal-linking' ); ?></h2>

			<?php if ( ! Crypto::available() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Secure key storage is unavailable on this server (the Sodium or OpenSSL PHP extension is required). The Search Console connection cannot be stored safely, so this feature is disabled.', 'ai-internal-linking' ); ?></p></div>
			<?php elseif ( ! GoogleServiceAccount::is_connected() ) : ?>
				<p class="description"><?php esc_html_e( 'Pull your Search Console performance data directly, with no CSV export. Uses a Google service account (read-only), so it also refreshes automatically on a schedule.', 'ai-internal-linking' ); ?></p>
				<ol class="ailinking-gsc-steps">
					<li><?php esc_html_e( 'In Google Cloud Console, create a project and enable the "Google Search Console API".', 'ai-internal-linking' ); ?></li>
					<li><?php esc_html_e( 'Create a Service Account, then create a JSON key for it and download the file.', 'ai-internal-linking' ); ?></li>
					<li><?php esc_html_e( 'Paste the JSON below (or upload the file) and click Connect.', 'ai-internal-linking' ); ?></li>
					<li><?php esc_html_e( 'In Search Console → Settings → Users and permissions, add the service-account email (shown after connecting) as a Full user.', 'ai-internal-linking' ); ?></li>
				</ol>
				<form method="post" action="<?php echo $post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ailinking_gsc_connect" />
					<?php wp_nonce_field( 'ailinking_gsc_connect' ); ?>
					<p>
						<label for="gsc_json"><strong><?php esc_html_e( 'Service-account JSON key', 'ai-internal-linking' ); ?></strong></label><br />
						<textarea name="gsc_json" id="gsc_json" rows="6" class="large-text code" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea>
					</p>
					<p>
						<label for="gsc_file"><?php esc_html_e( '…or upload the JSON file:', 'ai-internal-linking' ); ?></label>
						<input type="file" name="gsc_file" id="gsc_file" accept=".json,application/json" />
					</p>
					<?php submit_button( __( 'Connect', 'ai-internal-linking' ) ); ?>
				</form>
			<?php else : ?>
				<?php
				$email       = GoogleServiceAccount::client_email();
				$sites       = (array) get_option( 'ailinking_gsc_sites', array() );
				$property    = (string) Settings::get( 'gsc_property', '' );
				$range       = (int) Settings::get( 'gsc_range_days', 90 );
				$auto        = (bool) Settings::get( 'gsc_auto_sync', false );
				$last_sync   = (int) Settings::get( 'gsc_last_sync', 0 );
				$last_count  = (int) Settings::get( 'gsc_last_count', 0 );
				$last_status = (string) Settings::get( 'gsc_last_status', '' );
				$ranges      = array(
					28  => __( 'Last 28 days', 'ai-internal-linking' ),
					90  => __( 'Last 3 months', 'ai-internal-linking' ),
					180 => __( 'Last 6 months', 'ai-internal-linking' ),
					365 => __( 'Last 12 months', 'ai-internal-linking' ),
					480 => __( 'Last 16 months', 'ai-internal-linking' ),
				);
				?>
				<p class="description">
					<?php esc_html_e( 'Connected. Service-account email (add this as a user on your property in Search Console):', 'ai-internal-linking' ); ?>
					<br /><code><?php echo esc_html( $email ); ?></code>
				</p>

				<form method="post" action="<?php echo $post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline;">
					<input type="hidden" name="action" value="ailinking_gsc_verify" />
					<?php wp_nonce_field( 'ailinking_gsc_verify' ); ?>
					<?php submit_button( __( 'Re-check properties', 'ai-internal-linking' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo $post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="ailinking-gsc-settings">
					<input type="hidden" name="action" value="ailinking_gsc_save" />
					<?php wp_nonce_field( 'ailinking_gsc_save' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="gsc_property"><?php esc_html_e( 'Property', 'ai-internal-linking' ); ?></label></th>
							<td>
								<?php if ( empty( $sites ) ) : ?>
									<p class="description"><?php esc_html_e( 'No properties available yet. Add the service-account email above as a user in Search Console, then click "Re-check properties".', 'ai-internal-linking' ); ?></p>
								<?php else : ?>
									<select name="gsc_property" id="gsc_property">
										<?php foreach ( $sites as $s ) : ?>
											<?php $u = isset( $s['siteUrl'] ) ? (string) $s['siteUrl'] : ''; ?>
											<?php if ( '' === $u ) { continue; } ?>
											<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $property, $u ); ?>><?php echo esc_html( $u ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="gsc_range_days"><?php esc_html_e( 'Date range', 'ai-internal-linking' ); ?></label></th>
							<td>
								<select name="gsc_range_days" id="gsc_range_days">
									<?php foreach ( $ranges as $days => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $range, $days ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-sync', 'ai-internal-linking' ); ?></th>
							<td><label><input type="checkbox" name="gsc_auto_sync" value="1" <?php checked( $auto ); ?> /> <?php esc_html_e( 'Refresh automatically once a day', 'ai-internal-linking' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'ai-internal-linking' ), 'secondary' ); ?>
				</form>

				<p>
					<button type="button" class="button button-primary" id="ailinking-gsc-fetch" <?php disabled( '' === $property ); ?>><?php esc_html_e( 'Fetch now', 'ai-internal-linking' ); ?></button>
					<span class="description"><?php esc_html_e( 'Save your property first, then fetch. Rows replace the previous Search Console import.', 'ai-internal-linking' ); ?></span>
				</p>
				<div id="ailinking-progress-gsc" class="ailinking-progress" style="display:none;">
					<div class="ailinking-bar"><span></span></div>
					<p class="ailinking-progress-label"></p>
				</div>

				<?php if ( $last_sync > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: date/time, 2: row count, 3: status */
								__( 'Last fetch: %1$s — %2$s rows (%3$s).', 'ai-internal-linking' ),
								wp_date( 'Y-m-d H:i', $last_sync ),
								number_format_i18n( $last_count ),
								$last_status
							)
						);
						?>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo $post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect from Search Console? Imported keywords are kept.', 'ai-internal-linking' ) ); ?>');">
					<input type="hidden" name="action" value="ailinking_gsc_disconnect" />
					<?php wp_nonce_field( 'ailinking_gsc_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect', 'ai-internal-linking' ), 'link-delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
