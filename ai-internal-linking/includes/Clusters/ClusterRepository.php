<?php
/**
 * CRUD for topic clusters (pillar + spokes).
 *
 * @package AILinking
 */

namespace AILinking\Clusters;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class ClusterRepository {

	/**
	 * Create a cluster and register its pillar as a member.
	 *
	 * @param string $name   Cluster name.
	 * @param int    $pillar Pillar post id.
	 * @param string $lang   Language code.
	 * @param string $slug   Explicit slug. When empty, derived from $name. Auto-
	 *                       detection passes its own slug so idempotency checks
	 *                       (existing-slug lookups) match exactly what is stored.
	 * @return int Cluster id (0 on failure).
	 */
	public static function create( $name, $pillar, $lang = 'und', $slug = '' ) {
		global $wpdb;
		$slug = ( '' !== (string) $slug ) ? (string) $slug : sanitize_title( (string) $name );
		$ok   = $wpdb->insert(
			Tables::clusters(),
			array(
				'name'           => (string) $name,
				'slug'           => $slug,
				'pillar_post_id' => (int) $pillar,
				'lang_code'      => (string) $lang,
			),
			array( '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		if ( $pillar > 0 ) {
			self::add_member( $id, $pillar, 'pillar' );
		}
		return $id;
	}

	/**
	 * @param int $cluster_id Cluster id.
	 */
	public static function delete( $cluster_id ) {
		global $wpdb;
		$wpdb->delete( Tables::cluster_members(), array( 'cluster_id' => (int) $cluster_id ), array( '%d' ) );
		$wpdb->delete( Tables::clusters(), array( 'cluster_id' => (int) $cluster_id ), array( '%d' ) );
	}

	/**
	 * Add a member (idempotent on the unique key).
	 *
	 * @param int    $cluster_id Cluster id.
	 * @param int    $post_id    Post id.
	 * @param string $role       'pillar'|'spoke'.
	 */
	public static function add_member( $cluster_id, $post_id, $role = 'spoke' ) {
		global $wpdb;
		$table = Tables::cluster_members();
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE cluster_id=%d AND post_id=%d", $cluster_id, $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( $exists ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'cluster_id' => (int) $cluster_id,
				'post_id'    => (int) $post_id,
				'role'       => ( 'pillar' === $role ) ? 'pillar' : 'spoke',
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * @param int $cluster_id Cluster id.
	 * @param int $post_id    Post id.
	 */
	public static function remove_member( $cluster_id, $post_id ) {
		global $wpdb;
		$wpdb->delete( Tables::cluster_members(), array( 'cluster_id' => (int) $cluster_id, 'post_id' => (int) $post_id ), array( '%d', '%d' ) );
	}

	/**
	 * @return array[]
	 */
	public static function list_clusters() {
		global $wpdb;
		$table = Tables::clusters();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param int $cluster_id Cluster id.
	 * @return array|null
	 */
	public static function get( $cluster_id ) {
		global $wpdb;
		$table = Tables::clusters();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cluster_id=%d", $cluster_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? $row : null;
	}

	/**
	 * @param int $cluster_id Cluster id.
	 * @return array[]
	 */
	public static function members( $cluster_id ) {
		global $wpdb;
		$table = Tables::cluster_members();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE cluster_id=%d ORDER BY ( role = 'pillar' ) DESC, post_id ASC", $cluster_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Update cached cluster stats.
	 *
	 * @param int    $cluster_id Cluster id.
	 * @param float  $authority  Authority score.
	 * @param int    $is_flat    Flat flag.
	 * @param string $severity   Flat severity.
	 * @param int    $count      Member count.
	 */
	public static function update_stats( $cluster_id, $authority, $is_flat, $severity, $count ) {
		global $wpdb;
		$wpdb->update(
			Tables::clusters(),
			array(
				'authority_score' => (float) $authority,
				'is_flat'         => $is_flat ? 1 : 0,
				'flat_severity'   => (string) $severity,
				'member_count'    => (int) $count,
			),
			array( 'cluster_id' => (int) $cluster_id ),
			array( '%f', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Update a member's computed stats.
	 *
	 * @param int $id           Member row id.
	 * @param int $in_degree    Intra-cluster in-degree.
	 * @param int $links_to_hub 1 if it links to the pillar.
	 */
	public static function update_member_stats( $id, $in_degree, $links_to_hub ) {
		global $wpdb;
		$wpdb->update(
			Tables::cluster_members(),
			array( 'in_degree' => (int) $in_degree, 'links_to_hub' => $links_to_hub ? 1 : 0 ),
			array( 'id' => (int) $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}
}
