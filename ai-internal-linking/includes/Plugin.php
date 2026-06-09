<?php
/**
 * Main plugin orchestrator: wires hooks and lazily boots subsystems.
 *
 * @package AILinking
 */

namespace AILinking;

use AILinking\Install\Schema;
use AILinking\Indexer\Indexer;
use AILinking\Jobs\Scheduler;
use AILinking\Detectors\SiteDetector;
use AILinking\Admin\Admin;
use AILinking\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var int Re-entrancy depth for plugin-initiated saves. */
	private static $saving = 0;

	/**
	 * Begin a plugin-initiated content save (suppresses our own save_post handling).
	 * Depth-counted so nested guarded saves are handled correctly.
	 */
	public static function begin_internal_save() {
		self::$saving++;
	}

	/**
	 * End a plugin-initiated content save.
	 */
	public static function end_internal_save() {
		if ( self::$saving > 0 ) {
			self::$saving--;
		}
	}

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function boot() {
		// i18n.
		add_action(
			'init',
			function () {
				load_plugin_textdomain( 'ai-internal-linking', false, dirname( AILINKING_BASENAME ) . '/languages' );
			}
		);

		// Run any pending schema migrations after a plugin update.
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ), 30 );

		// Background scheduling.
		( new Scheduler() )->register();

		// Incremental indexing on content changes.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_remove_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_remove_post' ) );

		// Admin surfaces.
		if ( is_admin() ) {
			( new Admin() )->register();
			( new Ajax() )->register();
			add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
		}
	}

	/**
	 * Index a post when it is saved (incremental).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( self::$saving > 0 ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Indexer::scope_types(), true ) ) {
			return;
		}

		self::$saving = true;
		if ( 'publish' === $post->post_status ) {
			Indexer::index_post( $post_id );
		} else {
			Indexer::remove_post( $post_id );
		}
		self::$saving = false;
	}

	/**
	 * Remove a post from the index when trashed/deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_remove_post( $post_id ) {
		Indexer::remove_post( (int) $post_id );
	}

	/**
	 * One-time redirect to the onboarding wizard after activation.
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'ailinking_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'ailinking_activation_redirect' );

		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ailinking' ) );
		exit;
	}
}
