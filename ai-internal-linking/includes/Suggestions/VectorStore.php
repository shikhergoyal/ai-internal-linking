<?php
/**
 * Embedding storage + cosine re-ranking. Vectors are produced via the provider
 * Gateway and cached per post; the suggestion engine uses them to re-rank the
 * TF-IDF candidate set when embeddings are enabled. Degrades to TF-IDF otherwise.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Providers\Gateway;
use AILinking\Providers\UsageStats;
use AILinking\Jobs\ProgressStore;

defined( 'ABSPATH' ) || exit;

class VectorStore {

	const MAX_EMBED_CHARS = 8000;

	/**
	 * Pure cosine similarity of two equal-length vectors.
	 *
	 * @param float[] $a   Vector A.
	 * @param float[] $b   Vector B.
	 * @param float   $na  Precomputed norm of A (0 to compute).
	 * @param float   $nb  Precomputed norm of B (0 to compute).
	 * @return float [-1,1], or 0 on mismatch.
	 */
	public static function cosine( array $a, array $b, $na = 0.0, $nb = 0.0 ) {
		$len = count( $a );
		if ( 0 === $len || $len !== count( $b ) ) {
			return 0.0;
		}
		$dot = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}
		$na = $na > 0 ? $na : self::norm( $a );
		$nb = $nb > 0 ? $nb : self::norm( $b );
		if ( $na <= 0 || $nb <= 0 ) {
			return 0.0;
		}
		return $dot / ( $na * $nb );
	}

	/**
	 * L2 norm.
	 *
	 * @param float[] $v Vector.
	 * @return float
	 */
	public static function norm( array $v ) {
		$s = 0.0;
		foreach ( $v as $x ) {
			$s += $x * $x;
		}
		return sqrt( $s );
	}

	/**
	 * Store a post's embedding vector (upsert).
	 *
	 * @param int    $post_id      Post id.
	 * @param string $provider     Provider slug.
	 * @param string $model        Model.
	 * @param float[] $vector      Vector.
	 * @param string $content_hash Source content hash.
	 */
	public static function store( $post_id, $provider, $model, array $vector, $content_hash ) {
		global $wpdb;
		$table = Tables::embeddings();
		$norm  = self::norm( $vector );

		$data = array(
			'post_id'      => (int) $post_id,
			'provider'     => (string) $provider,
			'model'        => (string) $model,
			'dims'         => count( $vector ),
			'vector'       => wp_json_encode( $vector ),
			'norm'         => $norm,
			'content_hash' => (string) $content_hash,
		);
		$format = array( '%d', '%s', '%s', '%d', '%s', '%f', '%s' );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'post_id' => (int) $post_id ), $format, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $format );
		}
	}

	/**
	 * Fetch a post's vector.
	 *
	 * @param int $post_id Post id.
	 * @return array{vector:float[],norm:float,dims:int}|null
	 */
	public static function get( $post_id ) {
		global $wpdb;
		$table = Tables::embeddings();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT vector, norm, dims FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		$vec = json_decode( (string) $row['vector'], true );
		if ( ! is_array( $vec ) ) {
			return null;
		}
		return array(
			'vector' => array_map( 'floatval', $vec ),
			'norm'   => (float) $row['norm'],
			'dims'   => (int) $row['dims'],
		);
	}

	/**
	 * Re-rank TF-IDF candidates by blending cosine similarity (when vectors exist).
	 *
	 * @param int   $source_id  Source post id.
	 * @param array $candidates Candidate list from Tfidf::candidates().
	 * @return array Re-ranked candidates.
	 */
	public static function rerank( $source_id, array $candidates ) {
		if ( empty( $candidates ) ) {
			return $candidates;
		}
		$src = self::get( $source_id );
		if ( null === $src ) {
			return $candidates;
		}

		global $wpdb;
		$table = Tables::embeddings();
		$ids   = array_map(
			function ( $c ) {
				return (int) $c['post_id'];
			},
			$candidates
		);
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, vector, norm, dims FROM {$table} WHERE post_id IN ($ph)", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$vecs = array();
		foreach ( (array) $rows as $r ) {
			$v = json_decode( (string) $r['vector'], true );
			if ( is_array( $v ) && (int) $r['dims'] === $src['dims'] ) {
				$vecs[ (int) $r['post_id'] ] = array( 'vector' => array_map( 'floatval', $v ), 'norm' => (float) $r['norm'] );
			}
		}

		foreach ( $candidates as &$c ) {
			$pid = (int) $c['post_id'];
			if ( isset( $vecs[ $pid ] ) ) {
				$cos        = self::cosine( $src['vector'], $vecs[ $pid ]['vector'], $src['norm'], $vecs[ $pid ]['norm'] );
				$cos        = max( 0.0, $cos );
				$c['score'] = round( ( 0.5 * (float) $c['score'] ) + ( 0.5 * $cos ), 4 );
				$c['engine'] = 'embedding';
			}
		}
		unset( $c );

		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);
		return $candidates;
	}

	/**
	 * Build + store the embedding for one post via the Gateway.
	 *
	 * @param int $post_id Post id.
	 * @return array ['ok'=>bool,'error'?=>array]
	 */
	public static function build_for_post( $post_id ) {
		global $wpdb;
		$index = Tables::index();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT parsed_text, content_hash FROM {$index} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => array( 'class' => 'not_indexed' ) );
		}
		$text = substr( (string) $row['parsed_text'], 0, self::MAX_EMBED_CHARS );
		if ( '' === trim( $text ) ) {
			return array( 'ok' => true ); // nothing to embed.
		}

		$resp = Gateway::embed( array( $text ) );
		if ( empty( $resp['ok'] ) || empty( $resp['vectors'][0] ) ) {
			return array( 'ok' => false, 'error' => isset( $resp['error'] ) ? $resp['error'] : array( 'class' => 'unknown' ) );
		}

		self::store( $post_id, isset( $resp['provider'] ) ? $resp['provider'] : '', isset( $resp['model'] ) ? $resp['model'] : '', $resp['vectors'][0], (string) $row['content_hash'] );
		return array( 'ok' => true );
	}

	/**
	 * Begin an embedding build over the indexed corpus.
	 *
	 * @return array Progress snapshot.
	 */
	public static function start_build() {
		global $wpdb;

		// Resume a paused build rather than restarting from scratch.
		$existing = ProgressStore::get( 'embed' );
		if ( ! empty( $existing ) && 'paused' === ( isset( $existing['status'] ) ? $existing['status'] : '' ) ) {
			$existing['status'] = 'running';
			unset( $existing['error'], $existing['done'] );
			ProgressStore::set( 'embed', $existing );
			return $existing;
		}

		$index = Tables::index();
		$types = Settings::crawl_post_types();
		$total = 0;
		if ( ! empty( $types ) ) {
			$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE is_excluded=0 AND post_status='publish' AND post_type IN ($ph)", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$progress = array(
			'total'        => $total,
			'processed'    => 0,
			'cursor'       => 0,
			'status'       => ( $total > 0 && Gateway::embeddings_enabled() ) ? 'running' : 'complete',
			// Baseline for the live token ticker (embedding builds burn tokens too).
			'usage_log_id' => UsageStats::max_log_id(),
		);
		ProgressStore::set( 'embed', $progress );
		return $progress;
	}

	/**
	 * Process one batch of embedding builds (keyset cursor over the index).
	 *
	 * @param int $limit Posts per batch.
	 * @return array Progress snapshot with done/error flags.
	 */
	public static function build_batch( $limit = 5 ) {
		global $wpdb;

		// One embedding worker at a time.
		if ( ! ProgressStore::acquire( 'embed' ) ) {
			$progress = ProgressStore::get( 'embed' );
			if ( empty( $progress ) ) {
				$progress = array( 'total' => 0, 'processed' => 0, 'status' => 'running' );
			}
			$progress['done'] = ( 'complete' === ( isset( $progress['status'] ) ? $progress['status'] : '' ) );
			return $progress;
		}

		try {
		$index    = Tables::index();
		$embed    = Tables::embeddings();
		$progress = ProgressStore::get( 'embed' );
		$status   = isset( $progress['status'] ) ? $progress['status'] : '';
		if ( empty( $progress ) || ! in_array( $status, array( 'running', 'paused' ), true ) ) {
			$progress = self::start_build();
		} elseif ( 'paused' === $status ) {
			$progress['status'] = 'running'; // resume.
		}
		if ( 'running' !== $progress['status'] || ! Gateway::embeddings_enabled() ) {
			$progress['status'] = 'complete';
			$progress['done']   = true;
			ProgressStore::set( 'embed', $progress );
			return $progress;
		}

		$types  = Settings::crawl_post_types();
		$ph     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args   = $types;
		$args[] = (int) $progress['cursor'];
		$args[] = $limit;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.post_id FROM {$index} i
				 LEFT JOIN {$embed} e ON e.post_id = i.post_id AND e.content_hash = i.content_hash
				 WHERE i.is_excluded=0 AND i.post_status='publish' AND i.post_type IN ($ph)
				   AND i.post_id > %d AND e.id IS NULL
				 ORDER BY i.post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		);

		if ( empty( $ids ) ) {
			$progress['status'] = 'complete';
			$progress['done']   = true;
			ProgressStore::set( 'embed', $progress );
			return $progress;
		}

		foreach ( $ids as $pid ) {
			$res = self::build_for_post( (int) $pid );
			if ( empty( $res['ok'] ) ) {
				// Stop on budget/no-key/auth so we don't hammer a failing provider.
				$progress['status'] = 'paused';
				$progress['error']  = isset( $res['error']['class'] ) ? $res['error']['class'] : 'error';
				$progress['done']   = true;
				ProgressStore::set( 'embed', $progress );
				return $progress;
			}
			$progress['cursor'] = (int) $pid;
			$progress['processed']++;
		}

		$progress['done'] = false;
		ProgressStore::set( 'embed', $progress );
		return $progress;
		} finally {
			ProgressStore::release( 'embed' );
		}
	}
}
