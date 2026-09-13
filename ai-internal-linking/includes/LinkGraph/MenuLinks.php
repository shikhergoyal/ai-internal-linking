<?php
/**
 * Inbound links from navigation menus.
 *
 * An orphan is a page nothing links to. The audit only ever looked at links
 * inside article bodies, so a page sitting in the main navigation — reachable
 * from every page on the site, in one click, by every visitor — was reported as
 * an orphan. That is the opposite of true, and acting on it means adding links
 * to a page that is already as reachable as a page can be.
 *
 * Menu items are recorded as their own edges, with location='menu', so the
 * orphan audit can count them without any of the other audits being affected.
 * They are deliberately kept out of everything else:
 *
 * - Dead ends ask what a page links OUT to from its own body. A menu says
 *   nothing about that, and menu edges carry no meaningful source page anyway.
 * - Link density counts links written into the content. Menu items are not.
 * - PageRank stays content-only. A menu links from every page to the same
 *   handful of pages, so folding it in would flatten the whole graph and hide
 *   the editorial link structure the score exists to measure.
 *
 * Widgets, footers and shortcodes can link to a page too. Those live in too
 * many shapes to read reliably, so they are not covered here and the screen
 * says what an orphan now means rather than implying it means more.
 *
 * @package AILinking
 */

namespace AILinking\LinkGraph;

use AILinking\Support\Tables;
use AILinking\Content\UrlResolver;

defined( 'ABSPATH' ) || exit;

class MenuLinks {

	/**
	 * Rebuild every menu edge from the menus currently assigned to a theme
	 * location. A menu that is not assigned anywhere is not displayed, so it
	 * links to nothing.
	 *
	 * @return int Number of menu edges recorded.
	 */
	public static function scan() {
		global $wpdb;
		$table = Tables::link_graph();

		$wpdb->delete( $table, array( 'location' => 'menu' ), array( '%s' ) );

		if ( ! function_exists( 'get_nav_menu_locations' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return 0;
		}

		$menu_ids = array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) );
		if ( empty( $menu_ids ) ) {
			return 0;
		}

		// One edge per target, however many menus or items point at it: the
		// audit asks whether anything links here at all, not how often.
		$targets = array();
		foreach ( $menu_ids as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$post_id = self::target_of( $item );
				if ( $post_id > 0 ) {
					$targets[ $post_id ] = true;
				}
			}
		}

		foreach ( array_keys( $targets ) as $post_id ) {
			$wpdb->insert(
				$table,
				array(
					'source_post_id' => 0,
					'target_post_id' => (int) $post_id,
					'target_url'     => (string) get_permalink( (int) $post_id ),
					'target_url_norm' => UrlResolver::normalize( (string) get_permalink( (int) $post_id ) ),
					'target_kind'    => 'post',
					'anchor_text'    => '',
					'anchor_type'    => 'generic',
					'location'       => 'menu',
					'is_first_link'  => 0,
					'origin'         => 'discovered',
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		return count( $targets );
	}

	/**
	 * The post a menu item points at, or 0.
	 *
	 * A "post_type" item names its object directly, which is exact. Custom
	 * links carry a URL and have to be resolved like any other.
	 *
	 * @param \WP_Post $item Menu item.
	 * @return int
	 */
	private static function target_of( $item ) {
		$type = isset( $item->type ) ? (string) $item->type : '';

		if ( 'post_type' === $type && ! empty( $item->object_id ) ) {
			return (int) $item->object_id;
		}

		// Taxonomy items point at an archive, which is not a page in the index.
		if ( 'taxonomy' === $type ) {
			return 0;
		}

		$url = isset( $item->url ) ? (string) $item->url : '';
		if ( '' === $url || ! UrlResolver::is_internal( $url ) ) {
			return 0;
		}
		return (int) UrlResolver::to_post_id( $url );
	}
}
