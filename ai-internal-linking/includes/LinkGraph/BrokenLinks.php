<?php
/**
 * Broken internal-link detection over the discovered link graph.
 *
 * A link is reported only when nothing on this site claims the URL. It used to
 * be reported whenever the URL did not resolve to a post in the plugin's own
 * index, which is a different question with a much worse answer: every link to
 * a category, tag, author, date or shop archive was called broken, and so was
 * every link to a perfectly healthy post in a post type the reader had chosen
 * not to crawl. A report like that costs more time than it saves, because the
 * few genuinely broken links are buried in pages that were never broken.
 *
 * Two things changed. Unresolved URLs are handed to UrlClassifier, which asks
 * WordPress what the URL actually is. And a link that resolves to a real post
 * is now checked against that post's status in wp_posts rather than against
 * our index, because being outside the crawl scope says nothing about whether
 * a page exists.
 *
 * Judged by WordPress's routing, not by an HTTP request — so a page that
 * exists but whose server returns an error is not detectable here, and the
 * Link Health screen says so.
 *
 * @package AILinking
 */

namespace AILinking\LinkGraph;

use AILinking\Support\Tables;
use AILinking\Content\UrlClassifier;

defined( 'ABSPATH' ) || exit;

class BrokenLinks {

	/**
	 * Recompute the is_broken flag and the target kind across content edges.
	 *
	 * @return int Number of broken edges.
	 */
	public static function scan() {
		global $wpdb;
		$graph = Tables::link_graph();

		// Reset.
		$wpdb->query( "UPDATE {$graph} SET is_broken = 0 WHERE location='content'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		self::classify_unresolved();
		self::check_resolved();

		return self::count();
	}

	/**
	 * Ask WordPress what each unresolved URL is, once per distinct URL rather
	 * than once per edge — one category archive is typically linked from many
	 * pages, and the answer is the same every time.
	 */
	private static function classify_unresolved() {
		global $wpdb;
		$graph = Tables::link_graph();

		$rows = $wpdb->get_results(
			"SELECT target_url_norm, MIN(target_url) AS target_url FROM {$graph}
			  WHERE location='content' AND target_post_id = 0
			  GROUP BY target_url_norm", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$result = UrlClassifier::classify( (string) $row['target_url'] );

			// Resolvable after all. Permalinks change, and an edge recorded
			// before a change should not be called broken for it: adopt the id
			// so the graph, the audits and PageRank all see the real target.
			if ( UrlClassifier::POST === $result['kind'] && $result['post_id'] > 0 ) {
				$wpdb->update(
					$graph,
					array(
						'target_post_id' => (int) $result['post_id'],
						'target_kind'    => UrlClassifier::POST,
						'is_broken'      => 0,
					),
					array( 'location' => 'content', 'target_url_norm' => (string) $row['target_url_norm'] ),
					array( '%d', '%s', '%d' ),
					array( '%s', '%s' )
				);
				continue;
			}

			$wpdb->update(
				$graph,
				array(
					'target_kind' => (string) $result['kind'],
					'is_broken'   => UrlClassifier::UNKNOWN === $result['kind'] ? 1 : 0,
				),
				array( 'location' => 'content', 'target_url_norm' => (string) $row['target_url_norm'] ),
				array( '%s', '%d' ),
				array( '%s', '%s' )
			);
		}
	}

	/**
	 * Edges that resolved to a post: broken only if that post is really gone.
	 *
	 * Checked against wp_posts, not against our index. A published page in a
	 * post type the reader did not choose to crawl is missing from the index by
	 * their own instruction, and calling it a broken link for that is the
	 * plugin reporting its own settings back as a fault on the site.
	 *
	 * 'inherit' is accepted alongside 'publish' so a link to an attachment page
	 * is not reported; attachments carry their parent's status.
	 */
	private static function check_resolved() {
		global $wpdb;
		$graph = Tables::link_graph();

		$wpdb->query(
			"UPDATE {$graph} g
			 INNER JOIN {$wpdb->posts} p ON p.ID = g.target_post_id AND p.post_status IN ('publish','inherit')
			 SET g.target_kind = 'post', g.is_broken = 0
			 WHERE g.location='content' AND g.target_post_id > 0" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$wpdb->query(
			"UPDATE {$graph} g
			 LEFT JOIN {$wpdb->posts} p ON p.ID = g.target_post_id AND p.post_status IN ('publish','inherit')
			 SET g.is_broken = 1, g.target_kind = 'unknown'
			 WHERE g.location='content' AND g.target_post_id > 0 AND p.ID IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * @return int Broken edge count.
	 */
	public static function count() {
		global $wpdb;
		$graph = Tables::link_graph();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$graph} WHERE location='content' AND is_broken=1" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Links that point at a real page which is not a post — archives, feeds,
	 * search. Counted so the screen can say how many were previously being
	 * reported as broken and no longer are.
	 *
	 * @return int
	 */
	public static function archive_count() {
		global $wpdb;
		$graph = Tables::link_graph();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT COUNT(*) FROM {$graph} WHERE location='content' AND target_kind='archive'"
		);
	}

	/**
	 * Broken-edge list for the dashboard.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function listing( $limit = 50 ) {
		global $wpdb;
		$graph = Tables::link_graph();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_url, anchor_text FROM {$graph}
				 WHERE location='content' AND is_broken=1 ORDER BY source_post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
	}
}
