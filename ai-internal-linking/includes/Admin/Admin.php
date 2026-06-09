<?php
/**
 * Admin bootstrap: menu, asset loading, and page routing.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;

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

	public function __construct() {
		$this->wizard   = new Wizard();
		$this->inbox    = new Inbox();
		$this->settings = new SettingsPage();
		$this->health   = new HealthDashboard();
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
	}

	/**
	 * Register the admin menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'AI Internal Linking', 'ai-internal-linking' ),
			__( 'AI Linking', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking',
			array( $this->wizard, 'render' ),
			'dashicons-admin-links',
			58
		);

		add_submenu_page(
			'ailinking',
			__( 'Setup & Dashboard', 'ai-internal-linking' ),
			__( 'Setup & Dashboard', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking',
			array( $this->wizard, 'render' )
		);

		add_submenu_page(
			'ailinking',
			__( 'Suggestions', 'ai-internal-linking' ),
			__( 'Suggestions', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking-suggestions',
			array( $this->inbox, 'render' )
		);

		add_submenu_page(
			'ailinking',
			__( 'Link Health', 'ai-internal-linking' ),
			__( 'Link Health', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking-health',
			array( $this->health, 'render' )
		);

		add_submenu_page(
			'ailinking',
			__( 'Settings', 'ai-internal-linking' ),
			__( 'Settings', 'ai-internal-linking' ),
			Capabilities::MANAGE,
			'ailinking-settings',
			array( $this->settings, 'render' )
		);
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
				'i18n'    => array(
					'indexing'      => __( 'Indexing…', 'ai-internal-linking' ),
					'scanning'      => __( 'Scanning for suggestions…', 'ai-internal-linking' ),
					'removing'      => __( 'Removing inserted links…', 'ai-internal-linking' ),
					'auditing'      => __( 'Recomputing audits…', 'ai-internal-linking' ),
					'done'          => __( 'Done', 'ai-internal-linking' ),
					'error'         => __( 'Something went wrong. Please try again.', 'ai-internal-linking' ),
					'confirmReset'  => __( 'Re-index the whole site from scratch?', 'ai-internal-linking' ),
					'confirmRemove' => __( 'Revert every link this plugin inserted? Your content will be restored to its pre-link state.', 'ai-internal-linking' ),
					'modifiedSince' => __( 'This page was edited after the link was inserted. Undo anyway and overwrite those edits?', 'ai-internal-linking' ),
				),
			)
		);
	}
}
