<?php
/**
 * Suggestion inbox: the review queue. Approve, reject, apply and undo, one row
 * at a time or in bulk. Nothing here writes to content until it is applied, and
 * every apply saves a revision plus a ledger entry so it can be undone.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Tables;
use AILinking\Detectors\BuilderDetector;
use AILinking\Jobs\ProgressStore;
use AILinking\Providers\UsageStats;

defined( 'ABSPATH' ) || exit;

class Inbox {

	const PER_PAGE = 30;

	/**
	 * Scan controls: Start / Pause / Resume / Stop + progress bar. Shared by the
	 * Suggestions tab and the Setup dashboard. Initial button visibility reflects
	 * the server scan state so an in-progress scan stays resumable across page
	 * loads (JS refines it live).
	 *
	 * @return string HTML (already escaped).
	 */
	public static function scan_controls_html() {
		$sug       = ProgressStore::get( 'suggest' );
		$status    = isset( $sug['status'] ) ? (string) $sug['status'] : 'complete';
		$total     = (int) ( isset( $sug['total'] ) ? $sug['total'] : 0 );
		$proc      = (int) ( isset( $sug['processed'] ) ? $sug['processed'] : 0 );
		$resumable = in_array( $status, array( 'running', 'paused' ), true ) && $proc < $total;
		$pct       = $total > 0 ? min( 100, (int) round( ( $proc / $total ) * 100 ) ) : 0;
		$scan_disp = $resumable ? 'none' : 'inline-block';
		$res_disp  = $resumable ? 'inline-block' : 'none';

		// Tokens burned by the run so far. Rendered server-side as well as live,
		// so reloading mid-scan does not reset the counter to blank.
		$usage_html = '';
		if ( isset( $sug['usage_log_id'] ) ) {
			$usage = UsageStats::since_log_id( (int) $sug['usage_log_id'] );
			if ( $usage['requests'] > 0 ) {
				$usage_html = UsageStats::summary_html( $usage );
			}
		}

		ob_start();
		?>
		<p class="ailinking-scan-controls">
			<button class="button button-primary" id="ailinking-run-suggest" style="display:<?php echo esc_attr( $scan_disp ); ?>;"><?php esc_html_e( 'Scan for suggestions', 'ai-internal-linking' ); ?></button>
			<button class="button button-primary" id="ailinking-resume-suggest" style="display:<?php echo esc_attr( $res_disp ); ?>;"><?php esc_html_e( 'Resume', 'ai-internal-linking' ); ?></button>
			<button class="button" id="ailinking-pause-suggest" style="display:none;"><?php esc_html_e( 'Pause', 'ai-internal-linking' ); ?></button>
			<button class="button" id="ailinking-stop-suggest" style="display:<?php echo esc_attr( $res_disp ); ?>;"><?php esc_html_e( 'Stop', 'ai-internal-linking' ); ?></button>
		</p>
		<div class="ailinking-progress" id="ailinking-progress-suggest"
			data-scan-status="<?php echo esc_attr( $status ); ?>"
			data-scan-percent="<?php echo esc_attr( (string) $pct ); ?>"
			data-scan-processed="<?php echo esc_attr( (string) $proc ); ?>"
			data-scan-total="<?php echo esc_attr( (string) $total ); ?>"
			style="display:<?php echo $resumable ? 'block' : 'none'; ?>;">
			<div class="ailinking-bar"><span style="width:<?php echo esc_attr( (string) $pct ); ?>%;"></span></div>
			<p class="ailinking-progress-label"><?php echo $resumable ? esc_html( sprintf( /* translators: 1: done, 2: total */ __( 'Paused %1$d / %2$d', 'ai-internal-linking' ), $proc, $total ) ) : ''; ?></p>
			<div class="ailinking-usage" style="display:<?php echo '' !== $usage_html ? 'block' : 'none'; ?>;"><?php echo $usage_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in UsageStats::summary_html ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

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

		// Source (engine) filter within the current status.
		$engine_map = array(
			'ai'      => array( 'llm' ),
			'gsc'     => array( 'keyword' ),
			'content' => array( 'tfidf' ),
		);
		$engine = isset( $_GET['engine'] ) ? sanitize_key( wp_unslash( $_GET['engine'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $engine_map[ $engine ] ) ) {
			$engine = 'all';
		}

		// Per-source counts within the current status (for the filter row).
		$src_counts = array( 'all' => $counts[ $status ] );
		foreach ( $engine_map as $k => $engs ) {
			$ph               = implode( ',', array_fill( 0, count( $engs ), '%s' ) );
			$src_counts[ $k ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND engine IN ($ph)", array_merge( array( $status ), $engs ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Engine WHERE fragment for the list query.
		$engine_where  = '';
		$engine_params = array();
		if ( 'all' !== $engine ) {
			$engs          = $engine_map[ $engine ];
			$ph            = implode( ',', array_fill( 0, count( $engs ), '%s' ) );
			$engine_where  = " AND engine IN ($ph)";
			$engine_params = $engs;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s{$engine_where}", array_merge( array( $status ), $engine_params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$list_args = array_merge( array( $status ), $engine_params, array( self::PER_PAGE, $offset ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s{$engine_where} ORDER BY confidence_score DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$list_args
			),
			ARRAY_A
		);
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Suggestions', 'ai-internal-linking' ); ?></h1>

			<?php echo self::scan_controls_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in helper ?>

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
			<div style="clear:both;"></div>

			<ul class="subsubsub" style="float:none; margin: 2px 0 10px;">
				<li><span style="color:#646970;"><?php esc_html_e( 'Source:', 'ai-internal-linking' ); ?></span> </li>
				<?php
				$src_labels = array(
					'all'     => __( 'All', 'ai-internal-linking' ),
					'ai'      => __( 'AI Suggestion', 'ai-internal-linking' ),
					'gsc'     => __( 'GSC keyword', 'ai-internal-linking' ),
					'content' => __( 'Related Content', 'ai-internal-linking' ),
				);
				$sfirst = true;
				foreach ( $src_labels as $k => $label ) :
					$surl = add_query_arg(
						array(
							'page'   => 'ailinking',
							'tab'    => 'suggestions',
							'status' => $status,
							'engine' => $k,
						),
						admin_url( 'admin.php' )
					);
					?>
					<li>
						<?php echo $sfirst ? '' : '| '; ?>
						<a href="<?php echo esc_url( $surl ); ?>" class="<?php echo ( $k === $engine ) ? 'current' : ''; ?>">
							<?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $src_counts[ $k ] ) ); ?>)</span>
						</a>
					</li>
					<?php
					$sfirst = false;
				endforeach;
				?>
			</ul>
			<div style="clear:both;"></div>

			<?php
			// Only offer what can actually succeed here. On the Applied tab every
			// option is a no-op: applied rows are excluded from bulk status
			// changes by design, and re-applying them fails on status. Offering
			// them anyway just returns "0 of N done".
			$bulk_ops = array();
			if ( 'pending' === $status ) {
				$bulk_ops = array( 'approved', 'rejected', 'apply' );
			} elseif ( 'approved' === $status ) {
				$bulk_ops = array( 'apply', 'rejected', 'pending' );
			} elseif ( 'rejected' === $status ) {
				$bulk_ops = array( 'pending' );
			}
			$bulk_labels = array(
				'approved' => __( 'Approve', 'ai-internal-linking' ),
				'rejected' => __( 'Reject', 'ai-internal-linking' ),
				'pending'  => __( 'Move back to pending', 'ai-internal-linking' ),
				'apply'    => __( 'Apply to content', 'ai-internal-linking' ),
			);
			?>
			<?php if ( $bulk_ops ) : ?>
			<div class="ailinking-bulkbar" id="ailinking-bulkbar">
				<label class="screen-reader-text" for="ailinking-bulk-op"><?php esc_html_e( 'Bulk action', 'ai-internal-linking' ); ?></label>
				<select id="ailinking-bulk-op">
					<option value=""><?php esc_html_e( 'Bulk actions', 'ai-internal-linking' ); ?></option>
					<?php foreach ( $bulk_ops as $op ) : ?>
						<option value="<?php echo esc_attr( $op ); ?>"><?php echo esc_html( $bulk_labels[ $op ] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="ailinking-bulk-go" disabled><?php esc_html_e( 'Go', 'ai-internal-linking' ); ?></button>
				<span class="ailinking-bulk-count" id="ailinking-bulk-count"><?php esc_html_e( 'Nothing selected', 'ai-internal-linking' ); ?></span>
				<span class="ailinking-bulk-status" id="ailinking-bulk-status"></span>
				<p class="description">
					<?php esc_html_e( 'Applying writes the link into your content. Each one still saves a revision and an undo record first, and pages owned by a page builder are skipped rather than rewritten — you will be told how many.', 'ai-internal-linking' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped ailinking-inbox">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="ailinking-cb-all" title="<?php esc_attr_e( 'Select every suggestion on this page', 'ai-internal-linking' ); ?>" />
						</td>
						<th class="col-score" title="<?php esc_attr_e( 'Overall score blending relevance and naturalness', 'ai-internal-linking' ); ?>"><?php esc_html_e( 'Relevance', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'From (source page)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'To (target page)', 'ai-internal-linking' ); ?></th>
						<th><?php esc_html_e( 'Anchor & context', 'ai-internal-linking' ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', 'ai-internal-linking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No suggestions in this view. Run a scan to generate some.', 'ai-internal-linking' ); ?></td></tr>
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
							// Once per row: the checkbox and the action buttons both need it.
							$safety    = BuilderDetector::write_safety( BuilderDetector::detect( $source_id ) );
							?>
							<tr data-id="<?php echo esc_attr( $row['id'] ); ?>">
								<th scope="row" class="check-column">
									<input type="checkbox" class="ailinking-cb"
										value="<?php echo esc_attr( $row['id'] ); ?>"
										data-safety="<?php echo esc_attr( $safety ); ?>" />
								</th>
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
										} elseif ( 'llm' === $row['engine'] ) {
											echo ' <span class="ailinking-badge ailinking-badge-ai" title="' . esc_attr__( 'Proposed by your chat model from real page content.', 'ai-internal-linking' ) . '">' . esc_html__( 'AI Suggestion', 'ai-internal-linking' ) . '</span>';
										}
										?>
									</div>
								</td>
								<td class="col-actions">
									<?php if ( 'pending' === $row['status'] ) : ?>
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
				<p class="description"><?php esc_html_e( 'Approved suggestions are queued and ready. Use Apply on a row, or select several and choose “Apply to content” above — each insertion saves a revision and an undo record first.', 'ai-internal-linking' ); ?></p>
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
							'engine' => $engine,
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
