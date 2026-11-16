<?php
/**
 * Read-only figures for the accounting dashboard. Every query is independent and fails soft, so one
 * missing table (older schema, empty install) never breaks the page.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Dashboard_Service {
	/** Gregorian Y-m-d in the site timezone. */
	private static function today(): string {
		return current_time( 'Y-m-d' );
	}

	/** First day of the current month — Jalali month when dates are shown in Jalali. */
	public static function month_start(): string {
		$today = self::today();
		if ( ! empty( MDA_Settings::get( 'jalali_dates' ) ) ) {
			list( $y, $m, $d ) = array_map( 'intval', explode( '-', $today ) );
			$j                 = MDA_Jalali::to_jalali( $y, $m, $d );
			$g                 = MDA_Jalali::to_gregorian( $j[0], $j[1], 1 );
			return sprintf( '%04d-%02d-%02d', $g[0], $g[1], $g[2] );
		}
		return substr( $today, 0, 8 ) . '01';
	}

	private static function safe( callable $fn, $fallback = 0 ) {
		try {
			return $fn( MDA_Database::connection() );
		} catch ( Throwable $e ) {
			return $fallback;
		}
	}

	/** Sales total, product revenue and COGS of posted sales from $from (inclusive). */
	public static function sales_since( string $from ): array {
		return self::safe(
			static function ( PDO $db ) use ( $from ): array {
				$q = $db->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(total_minor),0) AS total, COALESCE(SUM(product_total_minor),0) AS products, COALESCE(SUM(cogs_total_minor),0) AS cogs FROM mda_sales_documents WHERE document_date >= :f' );
				$q->execute( array( ':f' => $from ) );
				$r = $q->fetch();
				return array(
					'count'  => (int) $r['n'],
					'total'  => (int) $r['total'],
					'profit' => (int) $r['products'] - (int) $r['cogs'],
				);
			},
			array( 'count' => 0, 'total' => 0, 'profit' => 0 )
		);
	}

	/** Net balance (debit − credit) of posted entries on accounts whose code starts with $prefix. */
	public static function balance( string $prefix ): int {
		return (int) self::safe(
			static function ( PDO $db ) use ( $prefix ): int {
				$q = $db->prepare(
					"SELECT COALESCE(SUM(e.debit_minor - e.credit_minor),0) FROM mda_journal_entries e
					JOIN mda_accounts a ON a.id = e.account_id JOIN mda_documents d ON d.id = e.document_id
					WHERE d.document_status = 'posted' AND a.account_code LIKE :p"
				);
				$q->execute( array( ':p' => $prefix . '%' ) );
				return (int) $q->fetchColumn();
			}
		);
	}

	/** Sales total per day for the last $days days (oldest first). */
	public static function daily_sales( int $days = 14 ): array {
		$days  = max( 7, min( 31, $days ) );
		$start = gmdate( 'Y-m-d', strtotime( self::today() . ' -' . ( $days - 1 ) . ' days' ) );
		$map   = self::safe(
			static function ( PDO $db ) use ( $start ): array {
				$q = $db->prepare( 'SELECT document_date AS d, SUM(total_minor) AS t FROM mda_sales_documents WHERE document_date >= :s GROUP BY document_date' );
				$q->execute( array( ':s' => $start ) );
				return $q->fetchAll( PDO::FETCH_KEY_PAIR );
			},
			array()
		);
		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d     = gmdate( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );
			$out[] = array( 'date' => $d, 'total' => (int) ( $map[ $d ] ?? 0 ) );
		}
		return $out;
	}

	/** Things that need the manager's attention. */
	public static function attention(): array {
		$failed     = (int) self::safe( static fn( PDO $db ) => (int) $db->query( "SELECT COUNT(*) FROM mda_integration_outbox WHERE status = 'failed'" )->fetchColumn() );
		$pending    = (int) self::safe( static fn( PDO $db ) => (int) $db->query( "SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing')" )->fetchColumn() );
		$backorders = (int) self::safe( static fn( PDO $db ) => (int) $db->query( 'SELECT COUNT(*) FROM mda_backorder_open' )->fetchColumn() );
		$review     = (int) self::safe( static fn( PDO $db ) => (int) $db->query( "SELECT COUNT(*) FROM mda_order_adjustments WHERE status = 'needs_review'" )->fetchColumn() );
		$cheques    = 0;
		try {
			$cheques = count( MDA_Cheque_Service::due( 7 ) );
		} catch ( Throwable $e ) {
			$cheques = 0;
		}
		return array(
			'failed'     => $failed,
			'pending'    => $pending,
			'backorders' => $backorders,
			'review'     => $review,
			'cheques'    => $cheques,
			'low_stock'  => self::low_stock_count(),
		);
	}

	/** Products at or below WooCommerce's low-stock threshold (lookup table, one cheap query). */
	public static function low_stock_count(): int {
		global $wpdb;
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$table     = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE stock_quantity IS NOT NULL AND stock_quantity <= %d", $threshold ) );
		return (int) $n;
	}

	public static function recent_sales( int $limit = 6 ): array {
		return self::safe(
			static function ( PDO $db ) use ( $limit ): array {
				$q = $db->prepare(
					'SELECT s.document_number, s.order_id, s.document_date, s.total_minor, s.source_type, p.display_name
					FROM mda_sales_documents s LEFT JOIN mda_parties p ON p.id = s.party_id ORDER BY s.id DESC LIMIT :l'
				);
				$q->bindValue( ':l', $limit, PDO::PARAM_INT );
				$q->execute();
				return $q->fetchAll();
			},
			array()
		);
	}
}
