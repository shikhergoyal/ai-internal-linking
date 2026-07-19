<?php
/**
 * Suggestion inbox: review queue. Approve / reject (status only in this build).
 * Applying approved links to content arrives in Phase 0b; this build never
 * mutates content.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Tables;
use AILinking\Detectors\BuilderDetector;

defined( 'ABSPATH' ) || exit;

class Inbox {

	const PER_PAGE = 30;

	/**
	 * Render the inbox.
	 */
	public function render() {
		Capabilities::require_manage();
		global $wpdb;
		$table = Tables::suggestions();

		$allowed = array( 'pending', 'approved', 'rejected', 'applied' );
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'pending';
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$counts = array();
		foreach ( $allowed as $s ) {
			$counts[ $s ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $s ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		$total = $counts[ $status ];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY confidence_score DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$status,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Suggestions', 'ai-internal-linking' ); ?></h1>

			<p>
				<button class="button button-primary" id="ailinking-run-suggest"><?php esc_html_e( 'Scan for suggestions', 'ai-internal-linking' ); ?></button>
			</p>
			<div class="ailinking-progress" id="ailinking-progress-suggest" style="display:none;">
				<div class="ailinking-bar"><span></span></div>
				<p class="ailinking-progress-label"></p>
			</div>

			<ul class="subsubsub">
				<?php
				$first = true;
				foreach ( $allowed as $s ) :
					$url = add_query_arg(
						array(
							'page'   => 'ailinking',
							'tab'    => 'suggestions',
							'status' => $s,
						),
						admin_url( 'admin.php' )
					);
					?>
					<li>
						<?php echo $first ? '' : '| '; ?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo ( $s === $status ) ? 'current' : ''; ?>">
							<?php echo esc_html( ucfirst( $s ) ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $s ] ) ); ?>)</span>
						</a>
					</li>
					<?php
					$first = false;
				endforeach;
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped ailinking-inbox">
				<thead>
					<tr>
						<th class="col-score"><?php esc_html_e( 'Confidence', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'From (source page)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'To (target page)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Anchor & context', 'ai-internal-linking' ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', 'ai-internal-linking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No suggestions in this view. Run a scan to generate some.', 'ai-internal-linking' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$source_id = (int) $row['source_post_id'];
							$target_id = (int) $row['target_post_id'];
							$edit_link = get_edit_post_link( $source_id );
							$src_title = get_the_title( $source_id );
							$tgt_title = $target_id ? get_the_title( $target_id ) : $row['target_url'];
							$tgt_link  = $target_id ? get_permalink( $target_id ) : $row['target_url'];
							$conf      = (int) round( (float) $row['confidence_score'] * 100 );
							?>
							<tr data-id="<?php echo esc_attr( $row['id'] ); ?>">
								<td class="col-score"><span class="ailinking-conf"><?php echo esc_html( $conf ); ?>%</span></td>
								<td>
									<?php if ( $edit_link ) : ?>
										<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $src_title ? $src_title : ( '#' . $source_id ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $src_title ? $src_title : ( '#' . $source_id ) ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $tgt_link ) : ?>
										<a href="<?php echo esc_url( $tgt_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $tgt_title ? $tgt_title : ( '#' . $target_id ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $tgt_title ); ?>
									<?php endif; ?>
								</td>
								<td>
									<strong class="ailinking-anchor"><?php echo esc_html( $row['anchor_text'] ); ?></strong>
									<div class="ailinking-context"><?php echo esc_html( $row['suggested_context'] ); ?></div>
									<div class="ailinking-scores">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: relevance, 2: naturalness */
												__( 'relevance %1$s · naturalness %2$s', 'ai-internal-linking' ),
												number_format_i18n( (float) $row['relevance_score'], 2 ),
												number_format_i18n( (float) $row['naturalness_score'], 2 )
											)
										);
										if ( 'keyword' === $row['engine'] ) {
											echo ' <span class="ailinking-badge ailinking-badge-kw" title="' . esc_attr__( 'The target page ranks for this keyword (imported from Google Search Console) and it appears here unlinked.', 'ai-internal-linking' ) . '">' . esc_html__( 'GSC keyword', 'ai-internal-linking' ) . '</span>';
										} elseif ( 'embedding' === $row['engine'] ) {
											echo ' <span class="ailinking-badge" title="' . esc_attr__( 'Re-ranked with semantic embeddings.', 'ai-internal-linking' ) . '">' . esc_html__( 'semantic', 'ai-internal-linking' ) . '</span>';
										} elseif ( 'llm' === $row['engine'] ) {
											echo ' <span class="ailinking-badge ailinking-badge-ai" title="' . esc_attr__( 'Proposed by your chat model from real page content.', 'ai-internal-linking' ) . '">' . esc_html__( 'AI Suggestion', 'ai-internal-linking' ) . '</span>';
										}
										?>
									</div>
								</td>
								<td class="col-actions">
									<?php
									$safety = BuilderDetector::write_safety( BuilderDetector::detect( $source_id ) );
									if ( 'pending' === $row['status'] ) :
										?>
										<button class="button button-small ailinking-act" data-status="approved"><?php esc_html_e( 'Approve', 'ai-internal-linking' ); ?></button>
										<button class="button button-small ailinking-act" data-status="rejected"><?php esc_html_e( 'Reject', 'ai-internal-linking' ); ?></button>
										<?php if ( 'auto' !== $safety ) : ?>
											<span class="ailinking-badge" title="<?php esc_attr_e( 'Page-builder content — applying must be done manually.', 'ai-internal-linking' ); ?>"><?php esc_html_e( 'manual', 'ai-internal-linking' ); ?></span>
										<?php endif; ?>
									<?php elseif ( 'approved' === $row['status'] ) : ?>
										<?php if ( 'auto' === $safety ) : ?>
											<button class="button button-small button-primary ailinking-apply"><?php esc_html_e( 'Apply', 'ai-internal-linking' ); ?></button>
										<?php else : ?>
											<span class="ailinking-badge" title="<?php esc_attr_e( 'This page is managed by a page builder; add the link manually using the anchor/context shown.', 'ai-internal-linking' ); ?>"><?php esc_html_e( 'Manual (builder)', 'ai-internal-linking' ); ?></span>
										<?php endif; ?>
										<button class="button button-small ailinking-act" data-status="pending"><?php esc_html_e( 'Unapprove', 'ai-internal-linking' ); ?></button>
									<?php elseif ( 'rejected' === $row['status'] ) : ?>
										<span class="ailinking-badge"><?php esc_html_e( 'Rejected', 'ai-internal-linking' ); ?></span>
										<button class="button button-small ailinking-act" data-status="pending"><?php esc_html_e( 'Restore', 'ai-internal-linking' ); ?></button>
									<?php else : ?>
										<span class="ailinking-badge ailinking-badge-ok"><?php esc_html_e( 'Applied', 'ai-internal-linking' ); ?></span>
										<?php if ( (int) $row['applied_ledger_id'] > 0 ) : ?>
											<button class="button button-small ailinking-undo" data-ledger="<?php echo esc_attr( $row['applied_ledger_id'] ); ?>"><?php esc_html_e( 'Undo', 'ai-internal-linking' ); ?></button>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( 'approved' === $status && $total > 0 ) : ?>
				<p class="description"><?php esc_html_e( 'Approved suggestions are queued. Applying them to your content (with backup + one-click undo) lands in the next phase.', 'ai-internal-linking' ); ?></p>
			<?php endif; ?>

			<?php
			$total_pages = (int) ceil( $total / self::PER_PAGE );
			if ( $total_pages > 1 ) {
				$links = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'add_args'  => array(
							'page'   => 'ailinking',
							'tab'    => 'suggestions',
							'status' => $status,
						),
					)
				);
				if ( $links ) {
					echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
				}
			}
			?>
		</div>
		<?php
	}
}
