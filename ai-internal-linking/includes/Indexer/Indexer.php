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
use AILinking\Content\UrlClassifier;
use AILinking\Suggestions\Tfidf;
use AILinking\Jobs\ProgressStore;

defined( 'ABSPATH' ) || exit;

class Indexer {

	/** Walking wp_posts forward on the keyset cursor. */
	const PHASE_CRAWL = 'crawl';

	/** Second attempt at the posts that threw during the crawl. */
	const PHASE_RETRY = 'retry';

	/** Removing index rows the crawl did not visit. */
	const PHASE_PRUNE = 'prune';

	/** Failures remembered per run. Enough to retry and to report; not a log. */
	const MAX_TRACKED_FAILURES = 200;


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
			// A run is the crawl, then a second attempt at whatever threw, then
			// removing rows the crawl never visited. It is not finished until
			// the index holds what the site holds, which the crawl alone cannot
			// achieve: walking forward over posts that qualify can never notice
			// one that has stopped qualifying.
			'phase'     => self::PHASE_CRAWL,
			'failed'    => array(),
			'unindexed' => array(),
			'pruned'    => 0,
			'status'    => 'running',
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

			$phase = isset( $progress['phase'] ) ? (string) $progress['phase'] : self::PHASE_CRAWL;

			if ( self::PHASE_RETRY === $phase ) {
				return self::retry_batch( $progress, $limit );
			}
			if ( self::PHASE_PRUNE === $phase ) {
				return self::prune_batch( $progress, $limit );
			}

			$types = self::scope_types();
			if ( empty( $types ) ) {
				// Nothing is in scope, so everything in the index is out of it.
				// Still a state worth pruning to rather than stopping at.
				return self::enter_phase( $progress, self::PHASE_PRUNE );
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
				// The crawl is over, but the run is not: posts that failed get a
				// second attempt, and the index still holds rows for posts this
				// crawl never visited.
				return self::enter_phase( $progress, self::PHASE_RETRY );
			}

			foreach ( $ids as $id ) {
				$id = (int) $id;
				// Isolate per-post failures so one bad post cannot stall the run.
				try {
					self::index_post( $id );
				} catch ( \Throwable $e ) {
					$progress = self::record_failure( $progress, $id, $e->getMessage() );
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
		// No need to clear the stored summary here: the upsert above is a
		// REPLACE, which rewrites the whole row, so an existing summary is
		// already gone and will be rebuilt from the new text on next use.

		return true;
	}

	/**
	 * Move the run into its next phase and persist that.
	 *
	 * @param array  $progress Progress snapshot.
	 * @param string $phase    Phase to enter.
	 * @return array
	 */
	private static function enter_phase( $progress, $phase ) {
		$progress['phase']  = $phase;
		$progress['status'] = 'running';
		$progress['done']   = false;
		ProgressStore::set( 'index', $progress );
		return $progress;
	}

	/**
	 * Remember a post that threw, so it can be tried again at the end of the run.
	 *
	 * last_error alone was not enough: it holds one message, overwritten by the
	 * next failure, so a post that failed simply stayed missing from the index
	 * until somebody noticed and re-ran everything.
	 *
	 * Depends only on its arguments, so it is covered by the unit suite.
	 *
	 * @param array  $progress Progress snapshot.
	 * @param int    $post_id  Post that failed.
	 * @param string $message  Failure message.
	 * @return array
	 */
	public static function record_failure( $progress, $post_id, $message ) {
		$progress['last_error'] = 'post ' . $post_id . ': ' . $message;

		$failed = isset( $progress['failed'] ) ? (array) $progress['failed'] : array();
		if ( count( $failed ) < self::MAX_TRACKED_FAILURES && ! in_array( (int) $post_id, $failed, true ) ) {
			$failed[] = (int) $post_id;
		}
		$progress['failed'] = $failed;

		return $progress;
	}

	/**
	 * Second attempt at the posts that threw, a batch at a time.
	 *
	 * Most indexing failures are transient — a timeout reaching an external
	 * service, a lock, a memory spike on one large post. Trying once more at the
	 * end of the run costs almost nothing and is usually the difference between
	 * a page being in the index and being silently absent from it.
	 *
	 * @param array $progress Progress snapshot.
	 * @param int   $limit    Posts per batch.
	 * @return array
	 */
	private static function retry_batch( $progress, $limit ) {
		$failed = isset( $progress['failed'] ) ? array_values( (array) $progress['failed'] ) : array();
		if ( empty( $failed ) ) {
			return self::enter_phase( $progress, self::PHASE_PRUNE );
		}

		$batch              = array_splice( $failed, 0, max( 1, (int) $limit ) );
		$progress['failed'] = $failed;

		$unindexed = isset( $progress['unindexed'] ) ? (array) $progress['unindexed'] : array();
		foreach ( $batch as $post_id ) {
			try {
				self::index_post( (int) $post_id );
			} catch ( \Throwable $e ) {
				// Twice is not bad luck. Record it and say so on the dashboard
				// rather than leaving a hole nobody knows about.
				$progress['last_error'] = 'post ' . (int) $post_id . ': ' . $e->getMessage();
				if ( count( $unindexed ) < self::MAX_TRACKED_FAILURES ) {
					$unindexed[] = (int) $post_id;
				}
			}
		}
		$progress['unindexed'] = $unindexed;

		ProgressStore::set( 'index', $progress );
		$progress['done'] = false;
		return $progress;
	}

	/**
	 * Remove index rows the crawl did not visit, a batch at a time.
	 *
	 * @param array $progress Progress snapshot.
	 * @param int   $limit    Rows per batch.
	 * @return array
	 */
	private static function prune_batch( $progress, $limit ) {
		$limit   = max( 1, (int) $limit );
		$removed = self::prune_stale( $limit );

		$progress['pruned'] = (int) ( isset( $progress['pruned'] ) ? $progress['pruned'] : 0 ) + $removed;

		if ( $removed >= $limit ) {
			// A full batch means there is probably more. Come back for it.
			ProgressStore::set( 'index', $progress );
			$progress['done'] = false;
			return $progress;
		}

		$progress['status'] = 'complete';
		$progress['done']   = true;
		ProgressStore::set( 'index', $progress );
		return $progress;
	}

	/**
	 * Prune until nothing stale is left, or the time budget runs out.
	 *
	 * For the moment a setting changes, where waiting for the next full index
	 * would mean living with a wrong Link Health count and a destination list
	 * containing pages the reader has just excluded. Bounded because this runs
	 * inside somebody's form submission; whatever is left over is picked up by
	 * the prune phase of the next indexing run.
	 *
	 * @param float $budget Seconds to spend at most.
	 * @return int Rows removed.
	 */
	public static function prune_stale_all( $budget = 5.0 ) {
		$started = microtime( true );
		$removed = 0;
		do {
			$batch    = self::prune_stale( 100 );
			$removed += $batch;
		} while ( $batch >= 100 && ( microtime( true ) - $started ) < (float) $budget );
		return $removed;
	}

	/**
	 * Drop index rows for posts that no longer qualify.
	 *
	 * A crawl only ever walks forward over the posts that currently qualify and
	 * writes what it finds, so nothing it does can remove a row for a post that
	 * has stopped qualifying. The live hooks catch a post being trashed,
	 * deleted or unpublished while the plugin is running, but not a post
	 * removed while it was deactivated, not a post deleted straight from the
	 * database by an import or a migration, and never a change of crawl scope —
	 * unticking a post type leaves every page of that type in the index for
	 * good, because the save hook ignores types it does not crawl.
	 *
	 * Those rows are not inert. They inflate the Link Health counts and they
	 * stay in the list of destinations the AI is offered, so the plugin can
	 * suggest linking to a page that no longer exists.
	 *
	 * @param int $limit Maximum rows to remove in one call.
	 * @return int Rows removed.
	 */
	public static function prune_stale( $limit = 100 ) {
		global $wpdb;
		$index = Tables::index();
		$limit = max( 1, (int) $limit );
		$types = self::scope_types();

		if ( empty( $types ) ) {
			$stale = $wpdb->get_col(
				$wpdb->prepare( "SELECT post_id FROM {$index} LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		} else {
			$ph     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$args   = $types;
			$args[] = $limit;
			$stale  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT i.post_id FROM {$index} i
					 LEFT JOIN {$wpdb->posts} p
					        ON p.ID = i.post_id AND p.post_status = 'publish' AND p.post_type IN ($ph)
					 WHERE p.ID IS NULL
					 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$args
				)
			);
		}

		foreach ( (array) $stale as $post_id ) {
			self::remove_post( (int) $post_id );
		}

		return count( (array) $stale );
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
			// Ask what the URL is, not just whether it is a post. A link to a
			// category or author archive resolves to no post and is not broken,
			// and the broken-link report has to be able to tell the two apart.
			$classified = UrlClassifier::classify( $url );
			$target_id  = (int) $classified['post_id'];
			$norm       = UrlResolver::normalize( $url );
			$anchor     = isset( $link['anchor'] ) ? $link['anchor'] : '';

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
					'target_kind'    => (string) $classified['kind'],
					'anchor_text'    => $anchor,
					'anchor_type'    => self::classify_anchor( $anchor ),
					'location'       => 'content',
					'is_first_link'  => $is_first,
					'origin'         => 'discovered',
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
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
