<?php
/**
 * Admin bootstrap: menu, asset loading, and page routing.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class Admin {

	/** @var Wizard */
	private $wizard;

	/** @var Inbox */
	private $inbox;

	/** @var SettingsPage */
	private $settings;

	/** @var HealthDashboard */
	private $health;

	/** @var KeyPoolPage */
	private $keys;

	/** @var KeywordsPage */
	private $keywords;

	public function __construct() {
		$this->wizard   = new Wizard();
		$this->inbox    = new Inbox();
		$this->settings = new SettingsPage();
		$this->health   = new HealthDashboard();
		$this->keys     = new KeyPoolPage();
		$this->keywords = new KeywordsPage();
	}

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// admin-post handlers for form submissions.
		$this->wizard->register();
		$this->settings->register();
		$this->keys->register();
		$this->keywords->register();
	}

	/**
	 * Ordered tab map: slug => [ label, page object ].
	 *
	 * @return array
	 */
	private function tabs() {
		return array(
			'dashboard'   => array( __( 'Setup & Dashboard', 'ai-internal-linking' ), $this->wizard ),
			'suggestions' => array( __( 'Suggestions', 'ai-internal-linking' ), $this->inbox ),
			'health'      => array( __( 'Link Health', 'ai-internal-linking' ), $this->health ),
			'keywords'    => array( __( 'Connect GSC', 'ai-internal-linking' ), $this->keywords ),
			'keys'        => array( __( 'AI Keys', 'ai-internal-linking' ), $this->keys ),
			'settings'    => array( __( 'Settings', 'ai-internal-linking' ), $this->settings ),
		);
	}

	/**
	 * Register a single top-level admin page; every section is a tab on it.
	 */
	public function menu() {
		add_menu_page(
			__( 'AI Internal Linking', 'ai-internal-linking' ),
			__( 'AI Linking', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking',
			array( $this, 'render_tabs' ),
			'dashicons-admin-links',
			58
		);
	}

	/**
	 * Render the tab bar, then the active section (its existing render()).
	 */
	public function render_tabs() {
		Capabilities::require_manage();

		$tabs   = $this->tabs();
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'dashboard';
		}

		echo '<div class="wrap ailinking-shell">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'AI Internal Linking', 'ai-internal-linking' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper ailinking-tabs">';
		foreach ( $tabs as $slug => $tab ) {
			$url = add_query_arg(
				array( 'page' => 'ailinking', 'tab' => $slug ),
				admin_url( 'admin.php' )
			);
			$cls = ( $slug === $active ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $tab[0] ) . '</a>';
		}
		echo '</h2>';
		echo '</div>';

		// Delegate to the section's existing renderer (unchanged behaviour).
		$tabs[ $active ][1]->render();
	}

	/**
	 * Enqueue assets on the plugin's admin pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'ailinking' ) ) {
			return;
		}

		wp_enqueue_style(
			'ailinking-admin',
			AILINKING_URL . 'assets/css/admin.css',
			array(),
			AILINKING_VERSION
		);

		wp_enqueue_script(
			'ailinking-admin',
			AILINKING_URL . 'assets/js/admin.js',
			array(),
			AILINKING_VERSION,
			true
		);

		wp_localize_script(
			'ailinking-admin',
			'AILinking',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ailinking_ajax' ),
				// Known chat models per provider, so the model pickers can rebuild
				// their options when the provider selection changes.
				'models'  => Registry::chat_models_map(),
				// Which providers actually need a base URL, so the key form can hide
				// fields that do not apply instead of explaining them away in a hint.
				'needsBase' => Registry::base_url_map(),
				'i18n'    => array(
					'indexing'      => __( 'Indexing…', 'ai-internal-linking' ),
					'scanning'      => __( 'Scanning for suggestions…', 'ai-internal-linking' ),
					'removing'      => __( 'Removing inserted links…', 'ai-internal-linking' ),
					'auditing'      => __( 'Recomputing audits…', 'ai-internal-linking' ),
					'fetchingGsc'   => __( 'Fetching from Search Console…', 'ai-internal-linking' ),
					'testing'       => __( 'Testing…', 'ai-internal-linking' ),
					'suggestOnly'   => __( 'This page is managed by a builder — add the link manually using the anchor/context shown.', 'ai-internal-linking' ),
					'cantPlace'     => __( 'Could not place this link automatically (the anchor wasn’t found uniquely). Try editing the anchor or apply manually.', 'ai-internal-linking' ),
					'done'          => __( 'Done', 'ai-internal-linking' ),
					/* translators: %s: number of selected rows */
					'nSelected'     => __( '%s selected', 'ai-internal-linking' ),
					'noneSelected'  => __( 'Nothing selected', 'ai-internal-linking' ),
					/* translators: %s: number of links */
					'confirmBulkApply' => __( 'Apply %s links to your content? Each one saves a revision and an undo record first.', 'ai-internal-linking' ),
					/* translators: %s: number of page-builder rows */
					'bulkManualNote' => __( '%s of them are on page-builder pages and will be skipped — those must be added manually.', 'ai-internal-linking' ),
					'bulkManualUnknown' => __( 'Any page-builder pages in this set are skipped rather than rewritten, and counted in the result.', 'ai-internal-linking' ),
					'bulkCollecting' => __( 'Collecting the full list…', 'ai-internal-linking' ),
					/* translators: %s: number processed so far */
					'bulkWorking'   => __( 'Working… %s processed', 'ai-internal-linking' ),
					/* translators: 1: number changed, 2: number attempted */
					'bulkDone'      => __( '%1$s of %2$s done', 'ai-internal-linking' ),
					/* translators: %s: number skipped */
					'bulkSkipped'   => __( '%s skipped', 'ai-internal-linking' ),
					/* translators: 1: tokens per page, 2: money for a whole scan, 3: page count */
					'estimate'      => __( 'About %1$s tokens per page — roughly %2$s for one full scan of %3$s pages.', 'ai-internal-linking' ),
					/* translators: %s: tokens per page */
					'estimateNone'  => __( 'About %s tokens per page. Index the site to see what a full scan would cost.', 'ai-internal-linking' ),
					'found'         => __( 'found', 'ai-internal-linking' ),
					'paused'        => __( 'Paused', 'ai-internal-linking' ),
					'confirmScan'   => __( 'Start a new scan? This replaces the current Pending suggestions (Approved and Applied ones are kept).', 'ai-internal-linking' ),
					'error'         => __( 'Something went wrong. Please try again.', 'ai-internal-linking' ),
					'confirmReset'  => __( 'Re-index the whole site from scratch?', 'ai-internal-linking' ),
					'confirmRemove' => __( 'Revert every link this plugin inserted? Your content will be restored to its pre-link state.', 'ai-internal-linking' ),
					'modifiedSince' => __( 'This page was edited after the link was inserted. Undo anyway and overwrite those edits?', 'ai-internal-linking' ),
				),
			)
		);
	}
}
