<?php
/**
 * Setup & Dashboard page: shows auto-detected environment, lets the user pick
 * crawl scope and link targets, and drives indexing + suggestion scans.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Settings;
use AILinking\Support\Tables;
use AILinking\Detectors\SiteDetector;
use AILinking\Jobs\ProgressStore;
use AILinking\Jobs\Scheduler;

defined( 'ABSPATH' ) || exit;

class Wizard {

	/**
	 * Register form handlers.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_save_wizard', array( $this, 'handle_save' ) );
	}

	/**
	 * Handle scope/settings save.
	 */
	public function handle_save() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_save_wizard' );

		$valid_types = array_keys( SiteDetector::public_post_types() );

		$crawl = isset( $_POST['crawl_post_types'] ) ? (array) wp_unslash( $_POST['crawl_post_types'] ) : array();
		$crawl = array_values( array_intersect( array_map( 'sanitize_key', $crawl ), $valid_types ) );

		$targets = isset( $_POST['target_post_types'] ) ? (array) wp_unslash( $_POST['target_post_types'] ) : array();
		$targets = array_values( array_intersect( array_map( 'sanitize_key', $targets ), $valid_types ) );

		$density = isset( $_POST['max_links_per_1000'] ) ? (int) $_POST['max_links_per_1000'] : 5;
		$density = max( 1, min( 20, $density ) );

		Settings::update(
			array(
				'crawl_post_types'   => $crawl,
				'target_post_types'  => $targets,
				'max_links_per_1000' => $density,
				'wizard_complete'    => true,
			)
		);

		wp_safe_redirect( add_query_arg( 'ailinking_saved', '1', admin_url( 'admin.php?page=ailinking' ) ) );
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();

		$summary  = SiteDetector::summary();
		$settings = Settings::all();
		$crawl    = Settings::crawl_post_types();
		$targets  = Settings::target_post_types();

		$indexed = $this->count_rows( Tables::index(), "is_excluded = 0" );
		$pending = $this->count_rows( Tables::suggestions(), "status = 'pending'" );

		$idx_prog = ProgressStore::get( 'index' );
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Setup & Dashboard', 'ai-internal-linking' ); ?></h1>

			<?php if ( isset( $_GET['ailinking_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ai-internal-linking' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! Scheduler::has_action_scheduler() ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Action Scheduler was not detected; the plugin uses WP-Cron with an in-browser fallback for indexing. Use the buttons below to run jobs interactively.', 'ai-internal-linking' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="ailinking-cards">
				<div class="ailinking-card">
					<h2><?php esc_html_e( 'Detected environment', 'ai-internal-linking' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr><th><?php esc_html_e( 'Public post types', 'ai-internal-linking' ); ?></th>
								<td><?php echo esc_html( implode( ', ', $summary['post_types'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Public taxonomies', 'ai-internal-linking' ); ?></th>
								<td><?php echo esc_html( $summary['taxonomies'] ? implode( ', ', $summary['taxonomies'] ) : '—' ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Page builders', 'ai-internal-linking' ); ?></th>
								<td><?php echo esc_html( $summary['builders'] ? implode( ', ', $summary['builders'] ) : __( 'None detected (Gutenberg/Classic)', 'ai-internal-linking' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Multilingual', 'ai-internal-linking' ); ?></th>
								<td><?php echo esc_html( $summary['multilingual'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'WooCommerce', 'ai-internal-linking' ); ?></th>
								<td><?php echo $summary['woocommerce'] ? esc_html__( 'Active', 'ai-internal-linking' ) : esc_html__( 'Not active', 'ai-internal-linking' ); ?></td></tr>
							<tr><th><?php esc_html_e( 'SEO plugin', 'ai-internal-linking' ); ?></th>
								<td><?php echo esc_html( $summary['seo_plugin'] ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="ailinking-card">
					<h2><?php esc_html_e( 'Status', 'ai-internal-linking' ); ?></h2>
					<p class="ailinking-stat"><span class="ailinking-num"><?php echo esc_html( number_format_i18n( $indexed ) ); ?></span> <?php esc_html_e( 'pages indexed', 'ai-internal-linking' ); ?></p>
					<p class="ailinking-stat"><span class="ailinking-num"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span> <?php esc_html_e( 'suggestions awaiting review', 'ai-internal-linking' ); ?></p>

					<h3><?php esc_html_e( 'Run', 'ai-internal-linking' ); ?></h3>
					<p>
						<button class="button button-primary" id="ailinking-run-index"><?php esc_html_e( 'Index / Re-index site', 'ai-internal-linking' ); ?></button>
						<button class="button" id="ailinking-run-suggest"><?php esc_html_e( 'Scan for suggestions', 'ai-internal-linking' ); ?></button>
					</p>
					<div class="ailinking-progress" id="ailinking-progress-index" style="display:none;">
						<div class="ailinking-bar"><span></span></div>
						<p class="ailinking-progress-label"></p>
					</div>
					<div class="ailinking-progress" id="ailinking-progress-suggest" style="display:none;">
						<div class="ailinking-bar"><span></span></div>
						<p class="ailinking-progress-label"></p>
					</div>
					<p><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=ailinking-suggestions' ) ); ?>"><?php esc_html_e( 'Review suggestions →', 'ai-internal-linking' ); ?></a></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ailinking-card ailinking-scope">
				<input type="hidden" name="action" value="ailinking_save_wizard" />
				<?php wp_nonce_field( 'ailinking_save_wizard' ); ?>
				<h2><?php esc_html_e( 'Scope', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose which content to crawl, and which content may be used as link targets.', 'ai-internal-linking' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Crawl these post types', 'ai-internal-linking' ); ?></th>
						<td>
							<?php foreach ( $summary['post_types'] as $slug => $label ) : ?>
								<label class="ailinking-check">
									<input type="checkbox" name="crawl_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $crawl, true ) ); ?> />
									<?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Valid link targets', 'ai-internal-linking' ); ?></th>
						<td>
							<?php foreach ( $summary['post_types'] as $slug => $label ) : ?>
								<label class="ailinking-check">
									<input type="checkbox" name="target_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $targets, true ) ); ?> />
									<?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ailinking-density"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="1" max="20" id="ailinking-density" name="max_links_per_1000" value="<?php echo esc_attr( (int) $settings['max_links_per_1000'] ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save scope', 'ai-internal-linking' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Count rows matching a trusted WHERE clause.
	 *
	 * @param string $table Table name.
	 * @param string $where Trusted WHERE clause (no user input).
	 * @return int
	 */
	private function count_rows( $table, $where ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
