<?php
/**
 * Runtime auto-detection of the site's structure: public post types, taxonomies,
 * multilingual plugin, WooCommerce, and SEO plugins. Makes no assumptions about
 * the site — everything is discovered at runtime.
 *
 * @package AILinking
 */

namespace AILinking\Detectors;

defined( 'ABSPATH' ) || exit;

class SiteDetector {

	/**
	 * Post types that are reasonable internal-linking candidates: publicly
	 * queryable, searchable, minus WordPress system types.
	 *
	 * @return array<string,\WP_Post_Type> Keyed by slug.
	 */
	public static function public_post_types() {
		$excluded = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );

		$types = get_post_types( array(), 'objects' );
		$out   = array();

		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			$linkable = ( true === $obj->publicly_queryable ) && ( false === $obj->exclude_from_search );
			// `page` reports publicly_queryable=false but is always linkable.
			if ( 'page' === $slug ) {
				$linkable = true;
			}
			if ( $linkable ) {
				$out[ $slug ] = $obj;
			}
		}

		return apply_filters( 'ailinking_public_post_types', $out );
	}

	/**
	 * Public taxonomies, excluding language taxonomies registered by multilingual plugins.
	 *
	 * @return array<string,\WP_Taxonomy> Keyed by slug.
	 */
	public static function public_taxonomies() {
		$excluded = array( 'language', 'post_translations', 'term_translations', 'term_language', 'post_format' );
		$taxes    = get_taxonomies( array( 'public' => true ), 'objects' );
		$out      = array();

		foreach ( $taxes as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			if ( false === $obj->publicly_queryable ) {
				continue;
			}
			$out[ $slug ] = $obj;
		}

		return apply_filters( 'ailinking_public_taxonomies', $out );
	}

	/**
	 * Detect the active multilingual plugin.
	 *
	 * @return string 'wpml' | 'polylang' | 'none'.
	 */
	public static function multilingual_plugin() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) {
			return 'wpml';
		}
		if ( defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_current_language' ) ) {
			return 'polylang';
		}
		return 'none';
	}

	/**
	 * Resolve a post's language code and the source plugin that supplied it.
	 *
	 * @param int $post_id Post ID.
	 * @return array{0:string,1:string} [ lang_code, lang_source ].
	 */
	public static function post_language( $post_id ) {
		$plugin = self::multilingual_plugin();

		if ( 'polylang' === $plugin && function_exists( 'pll_get_post_language' ) ) {
			$code = pll_get_post_language( $post_id, 'slug' );
			if ( $code ) {
				return array( substr( $code, 0, 10 ), 'polylang' );
			}
		}

		if ( 'wpml' === $plugin ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return array( substr( $details['language_code'], 0, 10 ), 'wpml' );
			}
		}

		return array( 'und', 'none' );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce system page IDs that should never be link targets.
	 *
	 * @return int[]
	 */
	public static function woo_system_page_ids() {
		if ( ! self::has_woocommerce() || ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Detect a known SEO plugin (informational; used for future schema hints).
	 *
	 * @return string 'yoast' | 'rankmath' | 'aioseo' | 'seopress' | 'none'.
	 */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return 'none';
	}

	/**
	 * A summary of the detected environment for the onboarding wizard.
	 *
	 * @return array
	 */
	public static function summary() {
		$post_types = array();
		foreach ( self::public_post_types() as $slug => $obj ) {
			$post_types[ $slug ] = $obj->labels->name;
		}

		$taxonomies = array();
		foreach ( self::public_taxonomies() as $slug => $obj ) {
			$taxonomies[ $slug ] = $obj->labels->name;
		}

		return array(
			'post_types'   => $post_types,
			'taxonomies'   => $taxonomies,
			'multilingual' => self::multilingual_plugin(),
			'woocommerce'  => self::has_woocommerce(),
			'seo_plugin'   => self::seo_plugin(),
			'builders'     => BuilderDetector::active_builders(),
		);
	}
}
