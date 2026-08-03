<?php
/**
 * Site indexer: crawls scoped posts into the custom index, building the text
 * corpus, TF-IDF term vectors, and the internal link graph. Uses a stable keyset
 * cursor (not OFFSET) so concurrent edits never cause skips/duplicates.
 *
 * @package AILinking
 */

namespace AILinking\Indexer;

use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Install\Schema;
use AILinking\Detectors\SiteDetector;
use AILinking\Detectors\BuilderDetector;
use AILinking\Content\ContentParser;
use AILinking\Content\UrlResolver;
use AILinking\Suggestions\Tfidf;
use AILinking\Jobs\ProgressStore;

defined( 'ABSPATH' ) || exit;

class Indexer {

	/**
	 * Scoped post types for crawling.
	 *
	 * @return string[]
	 */
	public static function scope_types() {
		return Settings::crawl_post_types();
	}

	/**
	 * Count publishable posts in scope.
	 *
	 * @return int
	 */
	public static function count_total() {
		global $wpdb;
		$types = self::scope_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL
				$types
			)
		);
	}

	/**
	 * Reset and start a full (re)index.
	 *
	 * @return array Progress snapshot.
	 */
	public static function start_full_reindex() {
		Schema::ensure_installed(); // guarantee tables exist before writing.
		// Which words count as site-wide is derived from the term table this run
		// is about to rewrite, so the cached answer must not outlive it.
		Tfidf::flush_site_wide_terms();
		$total    = self::count_total();
		$progress = array(
			'total'     => $total,
			'processed' => 0,
			'cursor'    => 0,
			'status'    => $total > 0 ? 'running' : 'complete',
		);
		ProgressStore::set( 'index', $progress );
		return $progress;
	}

	/**
	 * Process one batch using a keyset cursor on wp_posts.ID.
	 *
	 * @param int $limit Posts per batch.
	 * @return array Progress snapshot including a `done` flag.
	 */
	public static function process_batch( $limit = 15 ) {
		global $wpdb;

		// Serialise indexing: only one worker (admin AJAX OR cron) runs at a time,
		// otherwise they race the shared cursor — double-counting progress and
		// skipping rows. A busy caller just returns the current state.
		if ( ! ProgressStore::acquire( 'index' ) ) {
			$progress = ProgressStore::get( 'index' );
			if ( empty( $progress ) ) {
				$progress = array( 'total' => 0, 'processed' => 0, 'status' => 'running' );
			}
			$progress['done'] = ( 'complete' === ( isset( $progress['status'] ) ? $progress['status'] : '' ) );
			return $progress;
		}

		try {
			$progress = ProgressStore::get( 'index' );
			if ( empty( $progress ) ) {
				$progress = self::start_full_reindex();
			}

			$types = self::scope_types();
			if ( empty( $types ) ) {
				$progress['status'] = 'complete';
				$progress['done']   = true;
				ProgressStore::set( 'index', $progress );
				return $progress;
			}

			$cursor = (int) ( isset( $progress['cursor'] ) ? $progress['cursor'] : 0 );
			$ph     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$args   = $types;
			$args[] = $cursor;
			$args[] = $limit;

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_status = 'publish' AND post_type IN ($ph) AND ID > %d
					 ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$args
				)
			);

			if ( empty( $ids ) ) {
				$progress['status'] = 'complete';
				$progress['done']   = true;
				ProgressStore::set( 'index', $progress );
				return $progress;
			}

			foreach ( $ids as $id ) {
				$id = (int) $id;
				// Isolate per-post failures so one bad post can't stall the whole run.
				try {
					self::index_post( $id );
				} catch ( \Throwable $e ) {
					$progress['last_error'] = 'post ' . $id . ': ' . $e->getMessage();
				}
				$progress['cursor']    = $id; // advance even on failure (skip poison pill).
				$progress['processed'] = (int) ( isset( $progress['processed'] ) ? $progress['processed'] : 0 ) + 1;
			}

			$progress['done']   = false;
			$progress['status'] = 'running';
			ProgressStore::set( 'index', $progress );
			return $progress;
		} finally {
			ProgressStore::release( 'index' );
		}
	}

	/**
	 * Index (or re-index) a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the row was written.
	 */
	public static function index_post( $post_id ) {
		global $wpdb;
		$table = Tables::index();

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::scope_types(), true ) ) {
			return false;
		}

		$parsed = ContentParser::parse( $post );
		$text   = $parsed['text'];
		$system = $parsed['system'];

		list( $lang_code, $lang_source ) = SiteDetector::post_language( $post_id );

		$woo_system_ids = self::woo_system_ids();
		$is_woo_product = ( 'product' === $post->post_type ) ? 1 : 0;
		$is_woo_system  = in_array( (int) $post_id, $woo_system_ids, true ) ? 1 : 0;

		$is_excluded    = 0;
		$exclude_reason = '';
		if ( $is_woo_system ) {
			$is_excluded    = 1;
			$exclude_reason = 'woo_system';
		}

		$word_count   = (int) preg_match_all( '/\p{L}+/u', $text, $ignore );
		$content_hash = md5( $text . '|' . wp_json_encode( $parsed['links'] ) );
		$url          = (string) get_permalink( $post_id );
		$write_safety = BuilderDetector::write_safety( $system );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, content_hash FROM {$table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$post_id
			),
			ARRAY_A
		);

		$data = array(
			'post_id'        => $post_id,
			'post_type'      => $post->post_type,
			'post_status'    => $post->post_status,
			'title'          => $post->post_title,
			'url'            => $url,
			'content_system' => $system,
			'parsed_text'    => $text,
			'content_hash'   => $content_hash,
			'word_count'     => $word_count,
			'lang_code'      => $lang_code,
			'lang_source'    => $lang_source,
			'is_woo_product' => $is_woo_product,
			'is_woo_system'  => $is_woo_system,
			'is_excluded'    => $is_excluded,
			'exclude_reason' => $exclude_reason,
			'write_safety'   => $write_safety,
			'last_modified'  => $post->post_modified,
			'indexed_at'     => current_time( 'mysql' ),
		);
		$format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

		$changed = ( ! $existing || $existing['content_hash'] !== $content_hash );

		// Atomic upsert (REPLACE INTO, keyed on the unique post_id). This is
		// race-proof: if a concurrent cron/AJAX worker already inserted this post,
		// we overwrite instead of hitting "Duplicate entry for key post_id" (which
		// previously caused most posts to be skipped). Also surfaces real DB errors
		// (e.g. a missing table) instead of failing silently.
		$ok = $wpdb->replace( $table, $data, $format );
		if ( false === $ok ) {
			throw new \RuntimeException( 'index write failed: ' . ( $wpdb->last_error ? $wpdb->last_error : 'unknown DB error' ) );
		}

		// Heavy work only when content actually changed (or first index).
		if ( $changed || ! $existing ) {
			Tfidf::store_for_post( $post_id, $text );
			self::rebuild_link_graph( $post_id, $parsed['links'], $post->post_title );
		}

		return true;
	}

	/**
	 * Remove a post from the index and graph (e.g. on trash/delete).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remove_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( Tables::index(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( Tables::tfidf(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( Tables::link_graph(), array( 'source_post_id' => $post_id, 'origin' => 'discovered' ), array( '%d', '%s' ) );
	}

	/**
	 * Rebuild the discovered internal-link edges for a source post.
	 *
	 * @param int    $source_id    Source post ID.
	 * @param array  $links        Extracted links.
	 * @param string $source_title Unused placeholder for symmetry.
	 */
	private static function rebuild_link_graph( $source_id, $links, $source_title ) {
		global $wpdb;
		$table = Tables::link_graph();

		$wpdb->delete( $table, array( 'source_post_id' => $source_id, 'origin' => 'discovered' ), array( '%d', '%s' ) );

		if ( empty( $links ) ) {
			return;
		}

		$seen = array();
		foreach ( $links as $link ) {
			$url = isset( $link['url'] ) ? $link['url'] : '';
			if ( ! UrlResolver::is_internal( $url ) ) {
				continue;
			}
			$target_id = UrlResolver::to_post_id( $url );
			$norm      = UrlResolver::normalize( $url );
			$anchor    = isset( $link['anchor'] ) ? $link['anchor'] : '';

			$key       = $target_id > 0 ? ( 'p' . $target_id ) : ( 'u' . $norm );
			$is_first  = isset( $seen[ $key ] ) ? 0 : 1;
			$seen[ $key ] = true;

			$wpdb->insert(
				$table,
				array(
					'source_post_id' => $source_id,
					'target_post_id' => $target_id,
					'target_url'     => $url,
					'target_url_norm' => $norm,
					'anchor_text'    => $anchor,
					'anchor_type'    => self::classify_anchor( $anchor ),
					'location'       => 'content',
					'is_first_link'  => $is_first,
					'origin'         => 'discovered',
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Rough anchor-type classification.
	 *
	 * @param string $anchor Anchor text.
	 * @return string exact|partial|branded|generic|naked
	 */
	private static function classify_anchor( $anchor ) {
		$a = trim( strtolower( wp_strip_all_tags( (string) $anchor ) ) );
		if ( '' === $a ) {
			return 'generic';
		}
		if ( preg_match( '#^https?://#', $a ) || false !== strpos( $a, 'www.' ) ) {
			return 'naked';
		}
		$generic = array( 'click here', 'read more', 'here', 'this', 'learn more', 'more', 'link', 'this page', 'continue reading' );
		if ( in_array( $a, $generic, true ) ) {
			return 'generic';
		}
		return 'partial';
	}

	/**
	 * Cached WooCommerce system page IDs.
	 *
	 * @return int[]
	 */
	private static function woo_system_ids() {
		static $ids = null;
		if ( null === $ids ) {
			$ids = SiteDetector::woo_system_page_ids();
		}
		return $ids;
	}
}
