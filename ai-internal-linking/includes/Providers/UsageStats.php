<?php
/**
 * Token + cost accounting read model over the spend log.
 *
 * Every provider call already writes tokens_in/tokens_out/est_cost to
 * {$prefix}ailinking_spend_log (see KeyPoolManager::report_success). This class
 * is the read side: per-run deltas for the live scan ticker, per-key totals for
 * the AI Keys table, and month / all-time rollups for the usage summary.
 *
 * Cost is summed from spend_log.est_cost (decimal dollars) rather than the
 * per-key integer cents counter, because integer cents cannot represent a call
 * that costs a fraction of a cent, and most calls do.
 *
 * @package AILinking
 */

namespace AILinking\Providers;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class UsageStats {

	/**
	 * Highest spend-log id right now. Recorded when a job starts so the job's
	 * own usage can be measured as "everything logged after this point",
	 * which stays correct across pause/resume and page reloads.
	 *
	 * @return int
	 */
	public static function max_log_id() {
		global $wpdb;
		$table = Tables::spend_log();
		return (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Usage logged after a given spend-log id (i.e. one job run).
	 *
	 * @param int $log_id Baseline id.
	 * @return array{requests:int,tokens_in:int,tokens_out:int,cost:float}
	 */
	public static function since_log_id( $log_id ) {
		global $wpdb;
		$table = Tables::spend_log();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in),0)  AS tokens_in,
				        COALESCE(SUM(tokens_out),0) AS tokens_out,
				        COALESCE(SUM(est_cost),0)   AS cost
				 FROM {$table}
				 WHERE id > %d AND operation <> 'error'", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $log_id
			),
			ARRAY_A
		);
		return self::shape_row( $row );
	}

	/**
	 * Month-to-date or all-time totals.
	 *
	 * @param string $scope 'month'|'all'.
	 * @return array{requests:int,tokens_in:int,tokens_out:int,cost:float}
	 */
	public static function totals( $scope = 'all' ) {
		global $wpdb;
		$table = Tables::spend_log();

		if ( 'month' === $scope ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS requests,
					        COALESCE(SUM(tokens_in),0)  AS tokens_in,
					        COALESCE(SUM(tokens_out),0) AS tokens_out,
					        COALESCE(SUM(est_cost),0)   AS cost
					 FROM {$table}
					 WHERE operation <> 'error' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
					self::month_start()
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				"SELECT COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in),0)  AS tokens_in,
				        COALESCE(SUM(tokens_out),0) AS tokens_out,
				        COALESCE(SUM(est_cost),0)   AS cost
				 FROM {$table}
				 WHERE operation <> 'error'", // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		return self::shape_row( $row );
	}

	/**
	 * Per-key usage, keyed by key id.
	 *
	 * @param string $scope 'month'|'all'.
	 * @return array<int,array{requests:int,tokens_in:int,tokens_out:int,cost:float}>
	 */
	public static function by_key( $scope = 'month' ) {
		global $wpdb;
		$table = Tables::spend_log();

		if ( 'month' === $scope ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT key_id, COUNT(*) AS requests,
					        COALESCE(SUM(tokens_in),0)  AS tokens_in,
					        COALESCE(SUM(tokens_out),0) AS tokens_out,
					        COALESCE(SUM(est_cost),0)   AS cost
					 FROM {$table}
					 WHERE operation <> 'error' AND created_at >= %s
					 GROUP BY key_id", // phpcs:ignore WordPress.DB.PreparedSQL
					self::month_start()
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT key_id, COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in),0)  AS tokens_in,
				        COALESCE(SUM(tokens_out),0) AS tokens_out,
				        COALESCE(SUM(est_cost),0)   AS cost
				 FROM {$table}
				 WHERE operation <> 'error'
				 GROUP BY key_id", // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['key_id'] ] = self::shape_row( $r );
		}
		return $out;
	}

	/**
	 * Usage grouped by provider + model + operation. Chat and embedding calls
	 * have very different cost profiles, so they are never merged.
	 *
	 * @param string $scope 'month'|'all'.
	 * @return array[] Each row adds provider, model, operation to the totals.
	 */
	public static function breakdown( $scope = 'all' ) {
		global $wpdb;
		$table = Tables::spend_log();

		$where = "operation <> 'error'";
		if ( 'month' === $scope ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT provider, model, operation, COUNT(*) AS requests,
					        COALESCE(SUM(tokens_in),0)  AS tokens_in,
					        COALESCE(SUM(tokens_out),0) AS tokens_out,
					        COALESCE(SUM(est_cost),0)   AS cost
					 FROM {$table}
					 WHERE {$where} AND created_at >= %s
					 GROUP BY provider, model, operation
					 ORDER BY cost DESC, requests DESC", // phpcs:ignore WordPress.DB.PreparedSQL
					self::month_start()
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT provider, model, operation, COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in),0)  AS tokens_in,
				        COALESCE(SUM(tokens_out),0) AS tokens_out,
				        COALESCE(SUM(est_cost),0)   AS cost
				 FROM {$table}
				 WHERE {$where}
				 GROUP BY provider, model, operation
				 ORDER BY cost DESC, requests DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::shape_row( $r ) + array(
				'provider'  => (string) $r['provider'],
				'model'     => (string) $r['model'],
				'operation' => (string) $r['operation'],
			);
		}
		return $out;
	}

	/**
	 * Month-to-date cost in whole cents, for the spend cap. Rounded up so the
	 * cap can never be under-enforced.
	 *
	 * @return int
	 */
	public static function month_cost_cents() {
		$totals = self::totals( 'month' );
		return (int) ceil( $totals['cost'] * 100 );
	}

	/**
	 * Compact token count for display: 812, 12.4k, 3.05M. (pure)
	 *
	 * @param int $n Token count.
	 * @return string
	 */
	public static function format_tokens( $n ) {
		$n = max( 0, (int) $n );
		if ( $n < 1000 ) {
			return number_format_i18n( $n );
		}
		if ( $n < 1000000 ) {
			return number_format_i18n( $n / 1000, 1 ) . 'k';
		}
		return number_format_i18n( $n / 1000000, 2 ) . 'M';
	}

	/**
	 * Cost for display. Sub-dollar amounts keep 4 decimals, because a scan can
	 * legitimately cost a fraction of a cent and "$0.00" reads as free. (pure)
	 *
	 * @param float $dollars Cost in dollars.
	 * @return string
	 */
	public static function format_cost( $dollars ) {
		$dollars = max( 0.0, (float) $dollars );
		if ( $dollars > 0 && $dollars < 1 ) {
			return '$' . number_format_i18n( $dollars, 4 );
		}
		return '$' . number_format_i18n( $dollars, 2 );
	}

	/**
	 * One-line summary for the live ticker. Built server-side so the wording
	 * stays translatable and the browser only sets text.
	 *
	 * @param array $usage Usage totals.
	 * @return string
	 */
	public static function summary_text( array $usage ) {
		return sprintf(
			/* translators: 1: input tokens, 2: output tokens, 3: estimated cost, 4: request count */
			__( 'Tokens: %1$s in, %2$s out — est. %3$s over %4$s AI requests', 'ai-internal-linking' ),
			self::format_tokens( $usage['tokens_in'] ),
			self::format_tokens( $usage['tokens_out'] ),
			self::format_cost( $usage['cost'] ),
			number_format_i18n( (int) $usage['requests'] )
		);
	}

	/**
	 * Normalise a totals row. (pure)
	 *
	 * @param array|null $row Raw DB row.
	 * @return array{requests:int,tokens_in:int,tokens_out:int,cost:float}
	 */
	private static function shape_row( $row ) {
		return array(
			'requests'   => isset( $row['requests'] ) ? (int) $row['requests'] : 0,
			'tokens_in'  => isset( $row['tokens_in'] ) ? (int) $row['tokens_in'] : 0,
			'tokens_out' => isset( $row['tokens_out'] ) ? (int) $row['tokens_out'] : 0,
			'cost'       => isset( $row['cost'] ) ? (float) $row['cost'] : 0.0,
		);
	}

	/**
	 * First instant of the current month, in the site's timezone.
	 *
	 * created_at is written by MySQL's CURRENT_TIMESTAMP (database timezone)
	 * while this boundary comes from WordPress (site timezone). Where the two
	 * differ, a handful of calls near a month boundary can land on either side.
	 * That is immaterial for a usage report, and the spend cap rounds up, so it
	 * is never under-enforced.
	 *
	 * @return string MySQL datetime.
	 */
	private static function month_start() {
		return substr( (string) current_time( 'mysql' ), 0, 7 ) . '-01 00:00:00';
	}
}
