<?php
/**
 * Link Health dashboard: orphans, dead-ends, link density, click-depth, and the
 * count of plugin-inserted links (with a batched clean-removal action).
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\LinkGraph\GraphAudits;

defined( 'ABSPATH' ) || exit;

class HealthDashboard {

	/**
	 * Render the page.
	 */
	public function render() {
		Capabilities::require_manage();

		$summary = GraphAudits::summary();
		$depth   = $summary['depth'];
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Link Health', 'ai-internal-linking' ); ?></h1>

			<p>
				<button class="button" id="ailinking-run-audits"><?php esc_html_e( 'Recompute audits & click-depth', 'ai-internal-linking' ); ?></button>
				<button class="button button-link-delete" id="ailinking-remove-links" data-count="<?php echo esc_attr( $summary['applied_links'] ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of inserted links */
							__( 'Remove all inserted links (%s)', 'ai-internal-linking' ),
							number_format_i18n( $summary['applied_links'] )
						)
					);
					?>
				</button>
			</p>
			<div class="ailinking-progress" id="ailinking-progress-audits" style="display:none;">
				<div class="ailinking-bar"><span></span></div>
				<p class="ailinking-progress-label"></p>
			</div>

			<div class="ailinking-cards">
				<?php
				$this->stat_card( __( 'Indexed pages', 'ai-internal-linking' ), $summary['indexed'] );
				$this->stat_card( __( 'Orphans (no inbound links)', 'ai-internal-linking' ), $summary['orphans'] );
				$this->stat_card( __( 'Dead-ends (no outbound links)', 'ai-internal-linking' ), $summary['dead_ends'] );
				$this->stat_card( __( 'Over-linked pages', 'ai-internal-linking' ), $summary['over_linked'] );
				$this->stat_card( __( 'Under-linked pages', 'ai-internal-linking' ), $summary['under_linked'] );
				$this->stat_card( __( 'Plugin-inserted links', 'ai-internal-linking' ), $summary['applied_links'] );
				$this->stat_card( __( 'Deepest click-depth', 'ai-internal-linking' ), $depth['max'] );
				$this->stat_card( __( 'Unreachable from home', 'ai-internal-linking' ), $depth['unreached'] );
				?>
			</div>

			<?php
			$this->list_table( __( 'Orphan pages', 'ai-internal-linking' ), GraphAudits::orphans( 50 ), false );
			$this->list_table( __( 'Dead-end pages', 'ai-internal-linking' ), GraphAudits::dead_ends( 50 ), false );
			$this->list_table( __( 'Over-linked pages', 'ai-internal-linking' ), GraphAudits::over_linked( 50 ), true );
			?>
		</div>
		<?php
	}

	/**
	 * @param string $label Stat label.
	 * @param int    $value Stat value.
	 */
	private function stat_card( $label, $value ) {
		echo '<div class="ailinking-card ailinking-statcard">';
		echo '<p class="ailinking-num">' . esc_html( number_format_i18n( (int) $value ) ) . '</p>';
		echo '<p>' . esc_html( $label ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param string $title    Section title.
	 * @param array  $rows     Result rows.
	 * @param bool   $show_links Show link-count + word-count columns.
	 */
	private function list_table( $title, $rows, $show_links ) {
		echo '<div class="ailinking-card">';
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'None found.', 'ai-internal-linking' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'ai-internal-linking' ) . '</th>';
		if ( $show_links ) {
			echo '<th>' . esc_html__( 'Words', 'ai-internal-linking' ) . '</th><th>' . esc_html__( 'Links', 'ai-internal-linking' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Actions', 'ai-internal-linking' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$pid   = (int) $row['post_id'];
			$edit  = get_edit_post_link( $pid );
			$title = $row['title'] ? $row['title'] : ( '#' . $pid );
			echo '<tr><td>' . esc_html( $title ) . '</td>';
			if ( $show_links ) {
				echo '<td>' . esc_html( number_format_i18n( (int) $row['word_count'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $row['links'] ) ) . '</td>';
			}
			echo '<td>';
			if ( $edit ) {
				echo '<a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'ai-internal-linking' ) . '</a> ';
			}
			if ( ! empty( $row['url'] ) ) {
				echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $row['url'] ) . '">' . esc_html__( 'View', 'ai-internal-linking' ) . '</a>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
