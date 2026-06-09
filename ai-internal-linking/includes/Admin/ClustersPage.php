<?php
/**
 * Clusters page: define pillar/spoke topic clusters and view hub-and-spoke
 * authority + flat-cluster flags with concrete fixes.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Clusters\ClusterRepository;
use AILinking\Clusters\ClusterAnalyzer;
use AILinking\Detectors\SiteDetector;

defined( 'ABSPATH' ) || exit;

class ClustersPage {

	public function register() {
		add_action( 'admin_post_ailinking_create_cluster', array( $this, 'handle_create' ) );
		add_action( 'admin_post_ailinking_delete_cluster', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ailinking_add_spoke', array( $this, 'handle_add_spoke' ) );
		add_action( 'admin_post_ailinking_remove_spoke', array( $this, 'handle_remove_spoke' ) );
	}

	public function handle_create() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_create_cluster' );
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$pillar = isset( $_POST['pillar_post_id'] ) ? (int) $_POST['pillar_post_id'] : 0;
		if ( '' === $name ) {
			$this->redirect( 'need_name' );
		}
		list( $lang ) = $pillar > 0 ? SiteDetector::post_language( $pillar ) : array( 'und' );
		ClusterRepository::create( $name, $pillar, $lang );
		$this->redirect( 'created' );
	}

	public function handle_delete() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_delete_cluster' );
		ClusterRepository::delete( isset( $_POST['cluster_id'] ) ? (int) $_POST['cluster_id'] : 0 );
		$this->redirect( 'deleted' );
	}

	public function handle_add_spoke() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_add_spoke' );
		$cluster = isset( $_POST['cluster_id'] ) ? (int) $_POST['cluster_id'] : 0;
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $cluster && $post_id && get_post( $post_id ) ) {
			ClusterRepository::add_member( $cluster, $post_id, 'spoke' );
		}
		$this->redirect( 'spoke_added' );
	}

	public function handle_remove_spoke() {
		Capabilities::require_manage();
		check_admin_referer( 'ailinking_remove_spoke' );
		ClusterRepository::remove_member(
			isset( $_POST['cluster_id'] ) ? (int) $_POST['cluster_id'] : 0,
			isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0
		);
		$this->redirect( 'spoke_removed' );
	}

	private function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( 'ailinking_msg', $msg, admin_url( 'admin.php?page=ailinking-clusters' ) ) );
		exit;
	}

	public function render() {
		Capabilities::require_manage();
		$clusters = ClusterRepository::list_clusters();
		?>
		<div class="wrap ailinking-wrap">
			<h1><?php esc_html_e( 'AI Internal Linking — Clusters', 'ai-internal-linking' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Group a pillar (hub) page with its supporting posts. The analyzer flags “flat” clusters with no clear hub — a structure answer engines tend not to cite.', 'ai-internal-linking' ); ?></p>

			<?php if ( isset( $_GET['ailinking_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ailinking_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<p>
				<button class="button button-primary" id="ailinking-run-clusters"><?php esc_html_e( 'Analyze clusters', 'ai-internal-linking' ); ?></button>
				<span id="ailinking-cluster-status" class="description"></span>
			</p>

			<?php foreach ( $clusters as $c ) : ?>
				<?php
				$members = ClusterRepository::members( (int) $c['cluster_id'] );
				$flat    = ! empty( $c['is_flat'] );
				?>
				<div class="ailinking-card">
					<h2>
						<?php echo esc_html( $c['name'] ); ?>
						<?php if ( $flat ) : ?>
							<span class="ailinking-badge" style="background:#fcf0f1;color:#8a1f11;"><?php echo esc_html( sprintf( /* translators: severity */ __( 'flat (%s)', 'ai-internal-linking' ), $c['flat_severity'] ) ); ?></span>
						<?php else : ?>
							<span class="ailinking-badge ailinking-badge-ok"><?php echo esc_html( sprintf( /* translators: score */ __( 'authority %s', 'ai-internal-linking' ), number_format_i18n( (float) $c['authority_score'], 0 ) ) ); ?></span>
						<?php endif; ?>
					</h2>
					<p><strong><?php esc_html_e( 'Pillar:', 'ai-internal-linking' ); ?></strong>
						<?php echo $c['pillar_post_id'] ? esc_html( get_the_title( (int) $c['pillar_post_id'] ) ) : esc_html__( '— none set —', 'ai-internal-linking' ); ?>
					</p>
					<p class="description"><?php echo esc_html( ClusterAnalyzer::fix_hint( $c ) ); ?></p>

					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<th><?php esc_html_e( 'Member', 'ai-internal-linking' ); ?></th>
							<th><?php esc_html_e( 'Role', 'ai-internal-linking' ); ?></th>
							<th><?php esc_html_e( 'In-links (cluster)', 'ai-internal-linking' ); ?></th>
							<th><?php esc_html_e( 'Links to hub', 'ai-internal-linking' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $members as $m ) : ?>
								<tr>
									<td><?php echo esc_html( get_the_title( (int) $m['post_id'] ) ); ?></td>
									<td><?php echo esc_html( $m['role'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $m['in_degree'] ) ); ?></td>
									<td><?php echo ! empty( $m['links_to_hub'] ) ? '✓' : '—'; ?></td>
									<td>
										<?php if ( 'pillar' !== $m['role'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
												<input type="hidden" name="action" value="ailinking_remove_spoke" />
												<input type="hidden" name="cluster_id" value="<?php echo esc_attr( $c['cluster_id'] ); ?>" />
												<input type="hidden" name="post_id" value="<?php echo esc_attr( $m['post_id'] ); ?>" />
												<?php wp_nonce_field( 'ailinking_remove_spoke' ); ?>
												<button class="button button-small"><?php esc_html_e( 'Remove', 'ai-internal-linking' ); ?></button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
						<input type="hidden" name="action" value="ailinking_add_spoke" />
						<input type="hidden" name="cluster_id" value="<?php echo esc_attr( $c['cluster_id'] ); ?>" />
						<?php wp_nonce_field( 'ailinking_add_spoke' ); ?>
						<input type="number" name="post_id" placeholder="<?php esc_attr_e( 'spoke post ID', 'ai-internal-linking' ); ?>" />
						<button class="button"><?php esc_html_e( 'Add spoke', 'ai-internal-linking' ); ?></button>

						<span style="margin-left:auto"></span>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this cluster?', 'ai-internal-linking' ) ); ?>');">
						<input type="hidden" name="action" value="ailinking_delete_cluster" />
						<input type="hidden" name="cluster_id" value="<?php echo esc_attr( $c['cluster_id'] ); ?>" />
						<?php wp_nonce_field( 'ailinking_delete_cluster' ); ?>
						<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete cluster', 'ai-internal-linking' ); ?></button>
					</form>
				</div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ailinking-card">
				<input type="hidden" name="action" value="ailinking_create_cluster" />
				<?php wp_nonce_field( 'ailinking_create_cluster' ); ?>
				<h2><?php esc_html_e( 'New cluster', 'ai-internal-linking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cluster_name"><?php esc_html_e( 'Name', 'ai-internal-linking' ); ?></label></th>
						<td><input type="text" id="cluster_name" name="name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pillar_post_id"><?php esc_html_e( 'Pillar (hub) post ID', 'ai-internal-linking' ); ?></label></th>
						<td><input type="number" id="pillar_post_id" name="pillar_post_id" /> <span class="description"><?php esc_html_e( 'The post/page that should be the topical hub.', 'ai-internal-linking' ); ?></span></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create cluster', 'ai-internal-linking' ) ); ?>
			</form>
		</div>
		<?php
	}
}
