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
use AILinking\Install\Schema;
use AILinking\Detectors\SiteDetector;
use AILinking\Jobs\ProgressStore;
use AILinking\Jobs\Scheduler;
use AILinking\Suggestions\LlmSuggester;
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

		$density = isset( $_POST['max_links_per_1000'] ) ? (int) $_POST['max_links_per_1000'] : 5;
		$density = max( 1, min( 20, $density ) );

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

		Settings::update(
			array(
				'crawl_post_types'    => $crawl,
				'target_post_types'   => $targets,
				'max_links_per_1000'  => $density,
				'llm_max_words'       => $ai_words,
				'llm_candidates'      => $ai_candidates,
				'llm_candidate_words' => $ai_cand_words,
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

		$indexed = $this->count_rows( Tables::index(), "is_excluded = 0" );
		$pending = $this->count_rows( Tables::suggestions(), "status = 'pending'" );

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
					<p class="ailinking-stat"><span class="ailinking-num"><?php echo esc_html( number_format_i18n( $indexed ) ); ?></span> <?php esc_html_e( 'pages indexed', 'ai-internal-linking' ); ?></p>
					<p class="ailinking-stat"><span class="ailinking-num"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span> <?php esc_html_e( 'suggestions awaiting review', 'ai-internal-linking' ); ?></p>

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
						<th scope="row"><label for="ailinking-density"><?php esc_html_e( 'Max internal links per 1,000 words', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" min="1" max="20" id="ailinking-density" name="max_links_per_1000" value="<?php echo esc_attr( (int) $settings['max_links_per_1000'] ); ?>" /></td>
					</tr>
					<tr class="ailinking-subhead">
						<th colspan="2">
							<h3><?php esc_html_e( 'What the AI engine is given', 'ai-internal-linking' ); ?></h3>
							<p class="description">
								<?php esc_html_e( 'These three settings decide everything the model sees when it proposes a link, and between them they decide the bill. They do nothing at all unless “AI link suggestions” is switched on under Providers — the free engines always read the whole page and cost nothing.', 'ai-internal-linking' ); ?>
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
								<?php
								printf(
									/* translators: 1: minimum words, 2: largest preset, 3: hard ceiling */
									esc_html__( 'How much of each page the AI engine reads when "AI link suggestions" is on. Presets run from %1$d to %2$d words; choose Custom to enter any value up to %3$s. Anything past this point is invisible to the AI, so on long articles it only proposes links from the opening section.', 'ai-internal-linking' ),
									(int) LlmSuggester::MIN_WORDS,
									(int) LlmSuggester::PRESET_MAX_WORDS,
									esc_html( number_format_i18n( LlmSuggester::MAX_WORDS_LIMIT ) )
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'A page can only supply the words it actually has, so setting this above your longest article simply means "send the whole page". The ceiling exists so a mistyped value cannot exceed a model\'s context window, which fails the request and still bills for the attempt.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'This is the main cost lever: the bill scales almost linearly with it, because every post in a scan is one request. Doubling the words roughly doubles the input tokens. It has no effect at all unless AI link suggestions are enabled, and it never affects the free engines, which always read the whole page.', 'ai-internal-linking' ); ?>
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
								<?php esc_html_e( 'The model never searches your site. It is handed a shortlist of pages this one could link to, and it may only choose from that list. This is how long the list is.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: minimum, 2: maximum */
									esc_html__( 'The shortlist is the pages that share the most distinctive wording with the page being read, worked out by the free engine before the model is involved. A short list is cheap but may not contain the right destination at all; a long one costs more and invites weak picks, because a page ranked fiftieth by wording is on the list precisely because it has little in common. Anywhere from %1$d to %2$d.', 'ai-internal-linking' ),
									(int) LlmSuggester::MIN_CANDIDATES,
									(int) LlmSuggester::MAX_CANDIDATES_LIMIT
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ailinking-ai-candidate-words"><?php esc_html_e( 'Words describing each destination page', 'ai-internal-linking' ); ?></label></th>
						<td>
							<?php $ai_cw = LlmSuggester::clamp_candidate_words( isset( $settings['llm_candidate_words'] ) ? $settings['llm_candidate_words'] : LlmSuggester::DEFAULT_CANDIDATE_WORDS ); ?>
							<select id="ailinking-ai-candidate-words" name="llm_candidate_words">
								<?php foreach ( LlmSuggester::CANDIDATE_WORD_PRESETS as $preset ) : ?>
									<option value="<?php echo esc_attr( (string) $preset ); ?>" <?php selected( $preset === $ai_cw ); ?>>
										<?php
										if ( 0 === $preset ) {
											esc_html_e( 'Title only', 'ai-internal-linking' );
										} else {
											printf(
												/* translators: %s: number of words */
												esc_html__( '%s words', 'ai-internal-linking' ),
												esc_html( number_format_i18n( $preset ) )
											);
											echo $preset === LlmSuggester::DEFAULT_CANDIDATE_WORDS ? esc_html__( ' (default)', 'ai-internal-linking' ) : '';
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'How much the model is told about each page on that shortlist. On “Title only” it must judge a destination from its title alone, so two pages both called “Getting started” look identical to it. Adding words attaches that page’s own most-used words to its title, which is usually the cheapest way to get better picks.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'The words come from the index built during “Index / Re-index site”, not from a fresh read, so this costs no extra time — only the tokens shown below. Common words your whole site uses are already filtered out, so what is sent is what makes each page different.', 'ai-internal-linking' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'To be clear about what these words are not: they describe the destination page, and they never become link text. The anchor is always taken from the page being read — the words above — and is checked to appear there word-for-word before a suggestion is kept. These words only influence which destination gets chosen.', 'ai-internal-linking' ); ?>
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
							$est      = LlmSuggester::estimate_tokens( $ai_words, $ai_cands, $ai_cw );
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
								<?php
								if ( 'none' === $chat_provider || '' === $chat_model ) {
									esc_html_e( 'A rough figure for one full scan, updated as you change the three settings above. No chat model is configured yet, so the money figure uses a deliberately high fallback rate; set your provider and model under Providers for a closer number.', 'ai-internal-linking' );
								} else {
									printf(
										/* translators: 1: provider slug, 2: model id */
										esc_html__( 'A rough figure for one full scan, updated as you change the three settings above, priced for %1$s %2$s. The Providers screen reports what was actually billed.', 'ai-internal-linking' ),
										esc_html( $chat_provider ),
										esc_html( $chat_model )
									);
								}
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'It assumes every indexed page is scanned once and each is a single request. Real scans usually cost less, because pages already at their link limit are skipped before the model is asked anything.', 'ai-internal-linking' ); ?>
							</p>
						</td>
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
