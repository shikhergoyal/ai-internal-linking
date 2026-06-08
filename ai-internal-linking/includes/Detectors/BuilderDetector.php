<?php
/**
 * Per-post content-system detection (which editor/page-builder stores the body)
 * plus a site-level "which builders exist" probe for onboarding.
 *
 * Detection order matters: builders whose canonical content lives in postmeta
 * (Elementor, Beaver) are checked before shortcode-in-content builders (Divi,
 * WPBakery), then Gutenberg, then Classic.
 *
 * @package AILinking
 */

namespace AILinking\Detectors;

defined( 'ABSPATH' ) || exit;

class BuilderDetector {

	const GUTENBERG = 'gutenberg';
	const CLASSIC   = 'classic';
	const ELEMENTOR = 'elementor';
	const DIVI      = 'divi';
	const WPBAKERY  = 'wpbakery';
	const BEAVER    = 'beaver_builder';

	/**
	 * Detect the content system for a single post.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return string One of the class constants.
	 */
	public static function detect( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return self::CLASSIC;
		}
		$id = $post->ID;

		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) || '' !== (string) get_post_meta( $id, '_elementor_data', true ) ) {
			return self::ELEMENTOR;
		}

		if ( get_post_meta( $id, '_fl_builder_enabled', true ) ) {
			return self::BEAVER;
		}

		$content = (string) $post->post_content;

		if ( 'on' === get_post_meta( $id, '_et_pb_use_builder', true ) || false !== strpos( $content, '[et_pb_' ) ) {
			return self::DIVI;
		}

		if ( 'true' === get_post_meta( $id, '_wpb_vc_js_status', true ) || false !== strpos( $content, '[vc_row' ) ) {
			return self::WPBAKERY;
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return self::GUTENBERG;
		}

		return self::CLASSIC;
	}

	/**
	 * Whether a content system supports safe automated link insertion.
	 * (Used by the apply feature in Phase 0b; surfaced now for transparency.)
	 *
	 * @param string $system Content system.
	 * @return string 'auto' | 'suggest_only'.
	 */
	public static function write_safety( $system ) {
		$auto = array( self::GUTENBERG, self::CLASSIC, self::DIVI, self::WPBAKERY );
		return in_array( $system, $auto, true ) ? 'auto' : 'suggest_only';
	}

	/**
	 * Whether a post carries ACF fields (orthogonal to the primary system).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_acf( $post_id ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return false;
		}
		$fields = get_field_objects( $post_id );
		return ! empty( $fields );
	}

	/**
	 * Site-level list of builders that appear to be installed/active.
	 *
	 * @return string[]
	 */
	public static function active_builders() {
		$found = array();
		if ( defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) ) {
			$found[] = 'Elementor';
		}
		if ( class_exists( 'FLBuilder' ) ) {
			$found[] = 'Beaver Builder';
		}
		if ( function_exists( 'et_setup_theme' ) || defined( 'ET_BUILDER_VERSION' ) ) {
			$found[] = 'Divi';
		}
		if ( defined( 'WPB_VC_VERSION' ) ) {
			$found[] = 'WPBakery';
		}
		if ( function_exists( 'get_field' ) ) {
			$found[] = 'ACF';
		}
		return $found;
	}
}
