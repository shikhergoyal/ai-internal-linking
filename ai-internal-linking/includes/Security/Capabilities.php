<?php
/**
 * Capability checks. Phase 0a gates all admin surfaces behind `manage_options`.
 * A finer-grained custom capability is introduced alongside multi-user workflows
 * in a later phase.
 *
 * @package AILinking
 */

namespace AILinking\Security;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	const MANAGE = 'manage_options';

	/**
	 * Whether the current user may manage the plugin.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Die with a 403 if the current user cannot manage the plugin.
	 */
	public static function require_manage() {
		if ( ! self::can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'ai-internal-linking' ),
				403
			);
		}
	}
}
