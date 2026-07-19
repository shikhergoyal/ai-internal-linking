<?php
/**
 * Settings access layer. All plugin settings live in a single non-autoloaded option.
 *
 * @package AILinking
 */

namespace AILinking\Support;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'ailinking_settings';

	/**
	 * Default settings. Crawl/target scope default to null so the wizard can
	 * distinguish "never configured" from "explicitly empty".
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'wizard_complete'      => false,
			'crawl_post_types'     => null,   // array of post-type slugs once chosen
			'target_post_types'    => null,   // array of post-type slugs valid as link targets
			'provider'             => 'none', // Phase 0a: rule-based TF-IDF only
			'max_links_per_1000'   => 5,      // link-density ceiling (configurable)
			'min_relevance'        => 0.08,   // discard candidates below this cosine
			'max_suggestions_post' => 8,      // cap suggestions generated per source post
			'min_anchor_words'     => 2,      // 1 allows single-word anchors as a fallback
			'max_anchor_words'     => 4,      // longest anchor phrase to consider
			'keyword_suggestions'  => true,   // use imported keywords (GSC/Semrush) as a suggestion source
			// AI provider configuration (Phase 1).
			'chat_provider'             => 'none',
			'chat_model'                => '',
			'embedding_provider'        => 'none',
			'embedding_model'           => '',
			'reuse_chat_for_embeddings' => false,
			'rotation'                  => 'round_robin', // or primary_failover
			'monthly_cap_usd'           => 0,             // 0 = no cap
			// Google Search Console API fetch.
			'gsc_client_email'          => '',    // service-account email (display)
			'gsc_property'              => '',    // selected Search Console property
			'gsc_range_days'            => 90,    // performance window
			'gsc_auto_sync'             => false, // daily cron refresh
			'gsc_last_sync'             => 0,     // unix ts of last fetch
			'gsc_last_status'           => '',    // complete|paused
			'gsc_last_count'            => 0,     // rows stored on last fetch
		);
	}

	/**
	 * Full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) && null !== $all[ $key ] ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array $changes Key/value pairs to merge.
	 */
	public static function update( array $changes ) {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( $current, $changes );
		update_option( self::OPTION, $merged, false );
	}

	/**
	 * Effective crawl scope: chosen types, or a sensible default of all public types.
	 *
	 * @return string[]
	 */
	public static function crawl_post_types() {
		$chosen = self::get( 'crawl_post_types' );
		if ( is_array( $chosen ) && ! empty( $chosen ) ) {
			return $chosen;
		}
		return array_keys( \AILinking\Detectors\SiteDetector::public_post_types() );
	}

	/**
	 * Effective valid link-target types (defaults to the crawl scope).
	 *
	 * @return string[]
	 */
	public static function target_post_types() {
		$chosen = self::get( 'target_post_types' );
		if ( is_array( $chosen ) && ! empty( $chosen ) ) {
			return $chosen;
		}
		return self::crawl_post_types();
	}
}
