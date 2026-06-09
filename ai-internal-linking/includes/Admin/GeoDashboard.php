<?php
/**
 * GEO Readiness dashboard: site-level AI-search readiness, lowest-scoring pages
 * with concrete fixes, structured-data recommendations, and AI-crawler guidance.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Scorers\GeoReadiness;

defined( 'ABSPATH' ) || exit;

class GeoDashboard {

	/**
	 * AI answer-engine crawler user-agents (for robots.txt guidance).
	 *
	 * @return string[]
	 */
	private function ai_crawlers() {
		return array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'Google-Extended', 'PerplexityBot', 'ClaudeBot', 'Claude-SearchBot', 'CCBot', 'Bingbot', 'Applebot-Extended' );
	}

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();

		$scored = GeoReadiness::gather();
		$sum    = GeoReadiness::summary( $scored );
		$hints  = GeoReadiness::schema_hints();
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — GEO Readiness', 'ai-internal-linking' ); ?></h1>
			<p class="description"><?php esc_html_e( 'How well your content is positioned to be understood and cited by AI answer engines (ChatGPT, Gemini, Perplexity, Claude), based on internal structure. Run “Recompute audits” on Link Health first for accurate PageRank/depth.', 'ai-internal-linking' ); ?></p>

			<div class="ailinking-cards">
				<div class="ailinking-card ailinking-statcard"><p class="ailinking-num"><?php echo esc_html( number_format_i18n( $sum['avg'] ) ); ?></p><p><?php esc_html_e( 'Average GEO score', 'ai-internal-linking' ); ?></p></div>
				<div class="ailinking-card ailinking-statcard"><p class="ailinking-num"><?php echo esc_html( number_format_i18n( $sum['count'] ) ); ?></p><p><?php esc_html_e( 'Pages scored (sample)', 'ai-internal-linking' ); ?></p></div>
				<div class="ailinking-card ailinking-statcard"><p class="ailinking-num"><?php echo esc_html( number_format_i18n( $sum['low'] ) ); ?></p><p><?php esc_html_e( 'Pages below 40', 'ai-internal-linking' ); ?></p></div>
			</div>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'Lowest GEO-readiness pages', 'ai-internal-linking' ); ?></h2>
				<?php if ( empty( $sum['worst'] ) ) : ?>
					<p><?php esc_html_e( 'No pages scored yet. Index your site, then run audits.', 'ai-internal-linking' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<th><?php esc_html_e( 'Page', 'ai-internal-linking' ); ?></th>
							<th><?php esc_html_e( 'Score', 'ai-internal-linking' ); ?></th>
							<th><?php esc_html_e( 'Top fixes', 'ai-internal-linking' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $sum['worst'] as $row ) : ?>
								<?php
								$edit = get_edit_post_link( (int) $row['post_id'] );
								$name = $row['title'] ? $row['title'] : ( '#' . (int) $row['post_id'] );
								$fixes = array_slice( $row['fixes'], 0, 2 );
								?>
								<tr>
									<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ); ?></td>
									<td><span class="ailinking-conf"><?php echo esc_html( $row['score'] ); ?></span></td>
									<td><?php echo esc_html( implode( ' · ', $fixes ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'Structured data (schema) recommendations', 'ai-internal-linking' ); ?></h2>
				<p class="description">
					<?php
					if ( 'none' === $hints['seo_plugin'] ) {
						esc_html_e( 'No SEO plugin detected. Add one (Yoast, Rank Math, AIOSEO) or output JSON-LD so engines can parse your entities.', 'ai-internal-linking' );
					} else {
						echo esc_html( sprintf( /* translators: %s plugin */ __( 'Detected %s — it likely outputs base schema. Ensure the types below are enabled.', 'ai-internal-linking' ), $hints['seo_plugin'] ) );
					}
					?>
				</p>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th><?php esc_html_e( 'Post type', 'ai-internal-linking' ); ?></th><th><?php esc_html_e( 'Recommended schema', 'ai-internal-linking' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $hints['recommendations'] as $slug => $rec ) : ?>
							<tr><td><code><?php echo esc_html( $slug ); ?></code></td><td><?php echo esc_html( $rec ); ?></td></tr>
						<?php endforeach; ?>
						<tr><td><?php esc_html_e( 'All', 'ai-internal-linking' ); ?></td><td><?php esc_html_e( 'BreadcrumbList (reinforces the entity hierarchy)', 'ai-internal-linking' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="ailinking-card">
				<h2><?php esc_html_e( 'AI crawler access', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'To be eligible for AI answers, allow the crawlers you want in robots.txt. These power major answer engines:', 'ai-internal-linking' ); ?></p>
				<p><?php echo esc_html( implode( ', ', $this->ai_crawlers() ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Blocking them removes your content from those engines’ training/answers; allow the ones whose visibility you want.', 'ai-internal-linking' ); ?></p>
			</div>
		</div>
		<?php
	}
}
