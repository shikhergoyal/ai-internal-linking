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
use AILinking\Support\Stats;
use AILinking\Install\Schema;
use AILinking\Detectors\SiteDetector;
use AILinking\Jobs\ProgressStore;
use AILinking\Jobs\Scheduler;
use AILinking\Suggestions\LlmSuggester;
use AILinking\Suggestions\Summarizer;
use AILinking\Suggestions\SummaryStore;
use AILinking\Providers\Pricing;

defined( 'ABSPATH' ) || exit;

class Wizard {

	/**
	 * Register form handlers.
	 */
	public function register() {
		add_action( 'admin_post_ailinking_save_wizard', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ailinking_reset', array( $this, 'handle_reset' ) );
	}

	/**
	 * Clear scan data (keeps API keys + the Search Console connection) so the user
	 * can rescan from scratch.
	 */
	public function handle_reset() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_reset' );
		Schema::reset_data();
		wp_safe_redirect( add_query_arg( 'ailinking_msg', 'reset_done', admin_url( 'admin.php?page=ailinking&tab=dashboard' ) ) );
		exit;
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

		// A preset, or "custom" plus a free number. Clamped either way, since
		// this multiplies into the token bill on every post of every scan.
		$preset = isset( $_POST['llm_words_preset'] ) ? sanitize_key( wp_unslash( $_POST['llm_words_preset'] ) ) : '';
		if ( 'custom' === $preset ) {
			$ai_words = isset( $_POST['llm_max_words_custom'] ) ? (int) $_POST['llm_max_words_custom'] : LlmSuggester::DEFAULT_MAX_WORDS;
		} else {
			$ai_words = (int) $preset;
		}
		$ai_words = LlmSuggester::clamp_words( $ai_words );

		// Same preset-or-custom shape as the word budget above.
		$c_preset = isset( $_POST['llm_candidates_preset'] ) ? sanitize_key( wp_unslash( $_POST['llm_candidates_preset'] ) ) : '';
		if ( 'custom' === $c_preset ) {
			$ai_candidates = isset( $_POST['llm_candidates_custom'] ) ? (int) $_POST['llm_candidates_custom'] : LlmSuggester::DEFAULT_CANDIDATES;
		} else {
			$ai_candidates = (int) $c_preset;
		}
		$ai_candidates = LlmSuggester::clamp_candidates( $ai_candidates );

		// 0 is a real choice here ("titles only"), so no preset/custom split.
		$ai_cand_words = isset( $_POST['llm_candidate_words'] ) ? (int) $_POST['llm_candidate_words'] : LlmSuggester::DEFAULT_CANDIDATE_WORDS;
		$ai_cand_words = LlmSuggester::clamp_candidate_words( $ai_cand_words );

		// How a destination is described, and how long that description runs.
		// Both lengths are stored whichever mode is chosen, so switching back
		// and forth does not lose the other one's setting.
		$ai_mode    = LlmSuggester::clamp_describe_mode( isset( $_POST['llm_describe_mode'] ) ? sanitize_key( wp_unslash( $_POST['llm_describe_mode'] ) ) : '' );
		$ai_sum_len = Summarizer::clamp_words( isset( $_POST['llm_summary_words'] ) ? (int) $_POST['llm_summary_words'] : Summarizer::DEFAULT_WORDS );

		// Stored summaries are built at a specific length and mode, so a change
		// to either makes every one of them stale. Dropping them here is what
		// lets the build path stay simple: it never has to ask whether the
		// summary it found was built under the settings now in force.
		$before = Settings::all();
		if ( ( isset( $before['llm_summary_words'] ) ? (int) $before['llm_summary_words'] : 0 ) !== $ai_sum_len
			|| ( isset( $before['llm_describe_mode'] ) ? (string) $before['llm_describe_mode'] : '' ) !== $ai_mode ) {
			SummaryStore::forget_all();
		}

		Settings::update(
			array(
				'crawl_post_types'    => $crawl,
				'target_post_types'   => $targets,
				'llm_max_words'       => $ai_words,
				'llm_candidates'      => $ai_candidates,
				'llm_candidate_words' => $ai_cand_words,
				'llm_describe_mode'   => $ai_mode,
				'llm_summary_words'   => $ai_sum_len,
				'wizard_complete'     => true,
			)
		);

		wp_safe_redirect( add_query_arg( 'ailinking_saved', '1', admin_url( 'admin.php?page=ailinking&tab=dashboard' ) ) );
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

		// Same source the scan responses use, so the card cannot disagree with
		// itself once a running scan starts refreshing it.
		$indexed = Stats::indexed_pages();
		$pending = Stats::pending_suggestions();

		$idx_prog = ProgressStore::get( 'index' );
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Setup & Dashboard', 'ai-internal-linking' ); ?></h1>

			<?php if ( isset( $_GET['ailinking_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ai-internal-linking' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ailinking_msg'] ) && 'reset_done' === $_GET['ailinking_msg'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan data was cleared (your API keys and Search Console connection were kept). Click “Index / Re-index site” to rebuild from scratch.', 'ai-internal-linking' ); ?></p></div>
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
					<p class="ailinking-stat"><span class="ailinking-num" id="ailinking-stat-indexed"><?php echo esc_html( number_format_i18n( $indexed ) ); ?></span> <?php esc_html_e( 'pages indexed', 'ai-internal-linking' ); ?></p>
					<p class="ailinking-stat"><span class="ailinking-num" id="ailinking-stat-pending"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span> <?php esc_html_e( 'suggestions awaiting review', 'ai-internal-linking' ); ?></p>

					<?php if ( ! empty( $idx_prog['last_error'] ) ) : ?>
						<div class="notice notice-error inline"><p>
							<strong><?php esc_html_e( 'Last indexing error:', 'ai-internal-linking' ); ?></strong>
							<?php echo esc_html( $idx_prog['last_error'] ); ?>
						</p></div>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Run', 'ai-internal-linking' ); ?></h3>
					<p>
						<button class="button button-primary" id="ailinking-run-index"><?php esc_html_e( 'Index / Re-index site', 'ai-internal-linking' ); ?></button>
					</p>
					<div class="ailinking-progress" id="ailinking-progress-index" style="display:none;">
						<div class="ailinking-bar"><span></span></div>
						<p class="ailinking-progress-label"></p>
					</div>
					<?php echo \AILinking\Admin\Inbox::scan_controls_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in helper ?>
					<p><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=ailinking&tab=suggestions' ) ); ?>"><?php esc_html_e( 'Review suggestions →', 'ai-internal-linking' ); ?></a></p>
				</div>
			</div>

			<div class="ailinking-card ailinking-danger-zone">
				<h2><?php esc_html_e( 'Reset', 'ai-internal-linking' ); ?></h2>
				<p class="description">
					<strong class="ailinking-warn"><?php esc_html_e( 'Warning:', 'ai-internal-linking' ); ?></strong>
					<?php esc_html_e( 'This permanently deletes the scan data — the index, every suggestion, the link graph, keywords and the inserted-links log — so you can rescan from scratch. Your API keys, settings and Search Console connection are kept (you will NOT need to re-enter them). Links already inserted into your posts remain in the content (use “Remove all inserted links” on Link Health first if you want to revert those). This cannot be undone.', 'ai-internal-linking' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes the scan data (index, suggestions, link graph, keywords, inserted-links log) so you can rescan from scratch. Your API keys and Search Console connection are kept. This cannot be undone. Continue?', 'ai-internal-linking' ) ); ?>');">
					<input type="hidden" name="action" value="ailinking_reset" />
					<?php wp_nonce_field( 'ailinking_reset' ); ?>
					<button type="submit" class="button ailinking-danger"><?php esc_html_e( 'Reset all data', 'ai-internal-linking' ); ?></button>
				</form>
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
						<th scope="row"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></th>
						<td>
							<strong><?php echo esc_html( number_format_i18n( (int) $settings['max_links_per_1000'] ) ); ?></strong>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Settings tab */
									esc_html__( 'Shown here for reference. Change it on the %s tab, which is where it is stored — editing it in two places is how a stale form ends up overwriting your choice.', 'ai-internal-linking' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=ailinking&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'ai-internal-linking' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr class="ailinking-subhead">
						<th colspan="2">
							<h3><?php esc_html_e( 'What the AI engine is given', 'ai-internal-linking' ); ?></h3>
							<p class="description">
								<?php esc_html_e( 'These three settings decide everything the AI sees, and between them they decide the bill. They do nothing unless “AI link suggestions” is switched on under Providers.', 'ai-internal-linking' ); ?>
							</p>
						</th>
					</tr>
					<tr>
						<th scope="row"><label for="ailinking-ai-words"><?php esc_html_e( 'Words per page sent to the AI', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php
							$ai_words  = LlmSuggester::clamp_words( isset( $settings['llm_max_words'] ) ? $settings['llm_max_words'] : LlmSuggester::DEFAULT_MAX_WORDS );
							$presets   = LlmSuggester::PRESETS;
							$is_custom = ! in_array( $ai_words, $presets, true );
							?>
							<select id="ailinking-ai-words" name="llm_words_preset">
								<?php foreach ( $presets as $preset ) : ?>
									<option value="<?php echo esc_attr( (string) $preset ); ?>" <?php selected( ! $is_custom && $preset === $ai_words ); ?>>
										<?php
										printf(
											/* translators: %s: number of words */
											esc_html__( '%s words', 'ai-internal-linking' ),
											esc_html( number_format_i18n( $preset ) )
										);
										echo $preset === LlmSuggester::DEFAULT_MAX_WORDS ? esc_html__( ' (default)', 'ai-internal-linking' ) : '';
										?>
									</option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom…', 'ai-internal-linking' ); ?></option>
							</select>
							<input type="number" min="<?php echo esc_attr( (string) LlmSuggester::MIN_WORDS ); ?>"
								max="<?php echo esc_attr( (string) LlmSuggester::MAX_WORDS_LIMIT ); ?>" step="50"
								id="ailinking-ai-words-custom" name="llm_max_words_custom"
								value="<?php echo esc_attr( (string) $ai_words ); ?>"
								style="width:8em; display:<?php echo $is_custom ? 'inline-block' : 'none'; ?>;" />
							<p class="description">
								<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
								<?php
								printf(
									/* translators: 1: minimum words, 2: largest preset, 3: hard ceiling */
									esc_html__( 'Sets how much of each page the AI reads. Presets run %1$d to %2$d words; Custom accepts any number up to %3$s.', 'ai-internal-linking' ),
									(int) LlmSuggester::MIN_WORDS,
									(int) LlmSuggester::PRESET_MAX_WORDS,
									esc_html( number_format_i18n( LlmSuggester::MAX_WORDS_LIMIT ) )
								);
								?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Why it matters.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'The AI cannot see past this point, so on a long article it only proposes links from the opening section.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Cost.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'This is the main cost lever. Every page is one request, so doubling the words roughly doubles the bill. It does nothing unless AI link suggestions are on, and never affects the free engines, which always read the whole page.', 'ai-internal-linking' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ailinking-ai-candidates"><?php esc_html_e( 'Potential Destination Pages shown to AI', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php
							$ai_cands    = LlmSuggester::clamp_candidates( isset( $settings['llm_candidates'] ) ? $settings['llm_candidates'] : LlmSuggester::DEFAULT_CANDIDATES );
							$c_presets   = LlmSuggester::CANDIDATE_PRESETS;
							$c_is_custom = ! in_array( $ai_cands, $c_presets, true );
							?>
							<select id="ailinking-ai-candidates" name="llm_candidates_preset">
								<?php foreach ( $c_presets as $preset ) : ?>
									<option value="<?php echo esc_attr( (string) $preset ); ?>" <?php selected( ! $c_is_custom && $preset === $ai_cands ); ?>>
										<?php
										printf(
											/* translators: %s: number of pages */
											esc_html__( '%s pages', 'ai-internal-linking' ),
											esc_html( number_format_i18n( $preset ) )
										);
										echo $preset === LlmSuggester::DEFAULT_CANDIDATES ? esc_html__( ' (default)', 'ai-internal-linking' ) : '';
										?>
									</option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $c_is_custom ); ?>><?php esc_html_e( 'Custom…', 'ai-internal-linking' ); ?></option>
							</select>
							<input type="number" min="<?php echo esc_attr( (string) LlmSuggester::MIN_CANDIDATES ); ?>"
								max="<?php echo esc_attr( (string) LlmSuggester::MAX_CANDIDATES_LIMIT ); ?>" step="1"
								id="ailinking-ai-candidates-custom" name="llm_candidates_custom"
								value="<?php echo esc_attr( (string) $ai_cands ); ?>"
								style="width:8em; display:<?php echo $c_is_custom ? 'inline-block' : 'none'; ?>;" />
							<p class="description">
								<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'Sets how many pages the AI may choose between. It never searches your site — it only picks from this shortlist.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'How the list is built.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'The free engine ranks every eligible page by how much distinctive vocabulary it shares with the page being read, then passes over the top few. This happens before the AI is involved.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Choosing a number.', 'ai-internal-linking' ); ?></strong>
								<?php
								printf(
									/* translators: 1: minimum, 2: maximum */
									esc_html__( 'Too few, and the right page may not be on the list at all. Too many costs more and invites weak picks, because a page ranked fiftieth is there precisely because it has little in common. Anywhere from %1$d to %2$d.', 'ai-internal-linking' ),
									(int) LlmSuggester::MIN_CANDIDATES,
									(int) LlmSuggester::MAX_CANDIDATES_LIMIT
								);
								?>
							</p>
						</td>
					</tr>
										<tr>
						<th scope="row"><label for="ailinking-ai-describe"><?php esc_html_e( 'How each destination page is described', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php
							$ai_cw   = LlmSuggester::clamp_candidate_words( isset( $settings['llm_candidate_words'] ) ? $settings['llm_candidate_words'] : LlmSuggester::DEFAULT_CANDIDATE_WORDS );
							$ai_mode = LlmSuggester::describe_mode();
							$ai_sw   = Summarizer::clamp_words( isset( $settings['llm_summary_words'] ) ? $settings['llm_summary_words'] : Summarizer::DEFAULT_WORDS );
							?>
							<select id="ailinking-ai-describe" name="llm_describe_mode">
								<option value="summary" <?php selected( 'summary', $ai_mode ); ?>><?php esc_html_e( 'A short summary (recommended)', 'ai-internal-linking' ); ?></option>
								<option value="keywords" <?php selected( 'keywords', $ai_mode ); ?>><?php esc_html_e( 'A list of key words', 'ai-internal-linking' ); ?></option>
								<option value="title" <?php selected( 'title', $ai_mode ); ?>><?php esc_html_e( 'Title only', 'ai-internal-linking' ); ?></option>
							</select>
							<span class="ailinking-describe-len" data-mode="summary" style="display:<?php echo 'summary' === $ai_mode ? 'inline' : 'none'; ?>;">
								<label class="screen-reader-text" for="ailinking-ai-summary-words"><?php esc_html_e( 'Length of each summary', 'ai-internal-linking' ); ?></label>
									<select id="ailinking-ai-summary-words" name="llm_summary_words">
									<?php foreach ( Summarizer::PRESETS as $preset ) : ?>
										<option value="<?php echo esc_attr( (string) $preset ); ?>" <?php selected( $preset === $ai_sw ); ?>>
											<?php
											printf(
												/* translators: %s: number of words */
												esc_html__( '%s words', 'ai-internal-linking' ),
												esc_html( number_format_i18n( $preset ) )
											);
											echo $preset === Summarizer::DEFAULT_WORDS ? esc_html__( ' (default)', 'ai-internal-linking' ) : '';
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</span>
							<span class="ailinking-describe-len" data-mode="keywords" style="display:<?php echo 'keywords' === $ai_mode ? 'inline' : 'none'; ?>;">
								<label class="screen-reader-text" for="ailinking-ai-candidate-words"><?php esc_html_e( 'Key words per destination page', 'ai-internal-linking' ); ?></label>
									<select id="ailinking-ai-candidate-words" name="llm_candidate_words">
									<?php foreach ( LlmSuggester::CANDIDATE_WORD_PRESETS as $preset ) : ?>
										<?php if ( 0 === $preset ) { continue; } ?>
										<option value="<?php echo esc_attr( (string) $preset ); ?>" <?php selected( $preset === $ai_cw ); ?>>
											<?php
											printf(
												/* translators: %s: number of words */
												esc_html__( '%s words', 'ai-internal-linking' ),
												esc_html( number_format_i18n( $preset ) )
											);
											echo $preset === LlmSuggester::DEFAULT_CANDIDATE_WORDS ? esc_html__( ' (default)', 'ai-internal-linking' ) : '';
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</span>
							<p class="description">
								<strong><?php esc_html_e( 'What it does.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'Sets what the AI is told about each page on the shortlist, beyond its title.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Why a summary.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'On “Title only”, two pages both called “Getting started” look identical to the AI. A key-word list fixes that cheaply, but wastes room on near-duplicates such as “zone” and “zones”. A summary reads naturally and carries the unusual terms a short word list cuts off, which is usually the best value.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Where it comes from.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'The summary is built from the page’s own most representative sentences, using the index you already have — no AI call, and nothing is written into your pages. Wording your whole site repeats is ignored, so menus and standard intros cannot end up in it.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Not link text.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'This describes the destination page and never becomes the link. The anchor always comes from the page being read, and is checked to appear there word-for-word.', 'ai-internal-linking' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estimated cost', 'ai-internal-linking' ); ?></th>
						<td>
							<?php
							$chat_provider = (string) Settings::get( 'chat_provider', 'none' );
							$chat_model    = (string) Settings::get( 'chat_model', '' );
							// Cents per 1M tokens, resolved for the configured model.
							$rate_in  = Pricing::cents_float( $chat_provider, $chat_model, 1000000, 0 );
							$rate_out = Pricing::cents_float( $chat_provider, $chat_model, 0, 1000000 );
							// Words per destination depends on how they are described.
							$per_dest = ( 'summary' === $ai_mode ) ? $ai_sw : ( ( 'keywords' === $ai_mode ) ? $ai_cw : 0 );
							$est      = LlmSuggester::estimate_tokens( $ai_words, $ai_cands, $per_dest );
							?>
							<p id="ailinking-ai-estimate" class="ailinking-estimate"
								data-pages="<?php echo esc_attr( (string) $indexed ); ?>"
								data-rate-in="<?php echo esc_attr( (string) $rate_in ); ?>"
								data-rate-out="<?php echo esc_attr( (string) $rate_out ); ?>"
								data-overhead="<?php echo esc_attr( (string) LlmSuggester::PROMPT_OVERHEAD_TOKENS ); ?>"
								data-per-word="<?php echo esc_attr( (string) LlmSuggester::TOKENS_PER_WORD ); ?>"
								data-title-words="<?php echo esc_attr( (string) LlmSuggester::TITLE_WORDS ); ?>"
								data-reply="<?php echo esc_attr( (string) LlmSuggester::REPLY_TOKENS ); ?>">
								<?php
								printf(
									/* translators: 1: tokens per page, 2: page count */
									esc_html__( 'About %1$s tokens per page, across %2$s indexed pages.', 'ai-internal-linking' ),
									esc_html( number_format_i18n( $est['in'] + $est['out'] ) ),
									esc_html( number_format_i18n( $indexed ) )
								);
								?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'What this is.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'A rough figure for one full scan, updated as you change the three settings above.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'How it is priced.', 'ai-internal-linking' ); ?></strong>
								<?php
								if ( 'none' === $chat_provider || '' === $chat_model ) {
									esc_html_e( 'No chat model is set yet, so this uses a deliberately high fallback rate. Choose your provider and model under Providers for a closer number.', 'ai-internal-linking' );
								} else {
									printf(
										/* translators: 1: provider slug, 2: model id */
										esc_html__( 'Using your configured model, %1$s %2$s. The Providers screen reports what was actually billed.', 'ai-internal-linking' ),
										esc_html( $chat_provider ),
										esc_html( $chat_model )
									);
								}
								?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Why real scans cost less.', 'ai-internal-linking' ); ?></strong>
								<?php esc_html_e( 'This assumes every indexed page is scanned. In practice pages already at their link limit are skipped before the AI is asked anything.', 'ai-internal-linking' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save scope', 'ai-internal-linking' ) ); ?>
			</form>
		</div>
		<?php
	}

}
