<?php
/**
 * Keywords page: import a GSC/Semrush/CSV export and view striking-distance
 * opportunities (positions 5-20) mapped to posts.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Tables;
use AILinking\Integrations\KeywordImporter;

defined( 'ABSPATH' ) || exit;

class KeywordsPage {

	/**
	 * Register form handler.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_import_keywords', array( $this, 'handle_import' ) );
	}

	/**
	 * Handle CSV upload.
	 */
	public function handle_import() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_import_keywords' );

		$source = isset( $_POST['source'] ) && in_array( $_POST['source'], array( 'gsc', 'semrush', 'csv' ), true )
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
			<h1><?php esc_html_e( 'AI Internal Linking — Keywords', 'ai-internal-linking' ); ?></h1>

			<?php if ( isset( $_GET['ailinking_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ailinking_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ailinking-card">
				<input type="hidden" name="action" value="ailinking_import_keywords" />
				<?php wp_nonce_field( 'ailinking_import_keywords' ); ?>
				<h2><?php esc_html_e( 'Import keyword CSV', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Upload a Google Search Console performance export, a Semrush export, or any CSV with Query/Keyword, Clicks, Impressions, CTR, Position (and optionally Page/URL).', 'ai-internal-linking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="source"><?php esc_html_e( 'Source', 'ai-internal-linking' ); ?></label></th>
						<td>
							<select name="source" id="source">
								<option value="gsc"><?php esc_html_e( 'Google Search Console', 'ai-internal-linking' ); ?></option>
								<option value="semrush"><?php esc_html_e( 'Semrush', 'ai-internal-linking' ); ?></option>
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
}
