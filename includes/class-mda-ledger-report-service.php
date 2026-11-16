<?php
/**
 * Core accounting statements: trial balance, account ledger, balance sheet, receivable/payable aging.
 * Voided documents stay in the books together with their reversal, so both are always included.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Ledger_Report_Service {
	private const POSTED = "d.document_status IN ('posted','voided')";

	/**
	 * Four-column trial balance per account: opening, period debit, period credit, closing.
	 */
	public static function trial_balance( string $from, string $to ): array {
		$q = MDA_Database::connection()->prepare(
			'SELECT a.id, a.account_code, a.account_name, a.account_type, a.normal_balance,
				COALESCE(SUM(CASE WHEN d.document_date < :from THEN e.debit_minor - e.credit_minor END),0) AS opening,
				COALESCE(SUM(CASE WHEN d.document_date BETWEEN :from AND :to THEN e.debit_minor END),0) AS debit,
				COALESCE(SUM(CASE WHEN d.document_date BETWEEN :from AND :to THEN e.credit_minor END),0) AS credit
			FROM mda_accounts a
			LEFT JOIN mda_journal_entries e ON e.account_id = a.id
			LEFT JOIN mda_documents d ON d.id = e.document_id AND ' . self::POSTED . ' AND d.document_date <= :to
			GROUP BY a.id ORDER BY a.account_code'
		);
		$q->execute( array( ':from' => $from, ':to' => $to ) );
		$rows   = array();
		$totals = array( 'opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0 );
		foreach ( $q->fetchAll() as $r ) {
			$r['opening'] = (int) $r['opening'];
			$r['debit']   = (int) $r['debit'];
			$r['credit']  = (int) $r['credit'];
			$r['closing'] = $r['opening'] + $r['debit'] - $r['credit'];
			if ( 0 === $r['opening'] && 0 === $r['debit'] && 0 === $r['credit'] ) {
				continue;
			}
			foreach ( array_keys( $totals ) as $k ) {
				$totals[ $k ] += $r[ $k ];
			}
			$rows[] = $r;
		}
		return array( 'rows' => $rows, 'totals' => $totals, 'balanced' => $totals['debit'] === $totals['credit'] && 0 === $totals['closing'] );
	}

	/**
	 * Account ledger with running balance (debit-positive), optionally for one party.
	 */
	public static function account_ledger( int $account_id, string $from, string $to, int $party_id = 0, int $limit = 2000 ): array {
		$db     = MDA_Database::connection();
		$filter = $party_id > 0 ? ' AND e.party_id = :party' : '';
		$params = array( ':acc' => $account_id, ':from' => $from );
		if ( $party_id > 0 ) {
			$params[':party'] = $party_id;
		}
		$o = $db->prepare( 'SELECT COALESCE(SUM(e.debit_minor - e.credit_minor),0) FROM mda_journal_entries e JOIN mda_documents d ON d.id = e.document_id WHERE e.account_id = :acc AND ' . self::POSTED . ' AND d.document_date < :from' . $filter );
		$o->execute( $params );
		$opening = (int) $o->fetchColumn();

		$params[':to'] = $to;
		$q = $db->prepare(
			'SELECT d.id AS document_id, d.document_number, d.document_type, d.document_status, d.document_date, d.description, e.debit_minor, e.credit_minor, e.memo, p.display_name AS party_name
			FROM mda_journal_entries e JOIN mda_documents d ON d.id = e.document_id LEFT JOIN mda_parties p ON p.id = e.party_id
			WHERE e.account_id = :acc AND ' . self::POSTED . ' AND d.document_date BETWEEN :from AND :to' . $filter . '
			ORDER BY d.document_date, d.id, e.entry_number LIMIT ' . max( 1, min( 10000, $limit ) )
		);
		$q->execute( $params );
		$balance = $opening;
		$rows    = array();
		foreach ( $q->fetchAll() as $r ) {
			$balance       += (int) $r['debit_minor'] - (int) $r['credit_minor'];
			$r['balance']   = $balance;
			$rows[]         = $r;
		}
		return array( 'opening' => $opening, 'rows' => $rows, 'closing' => $balance );
	}

	/**
	 * Balance sheet at a date; current-year profit is shown inside equity until the period is closed.
	 */
	public static function balance_sheet( string $as_of ): array {
		$q = MDA_Database::connection()->prepare(
			'SELECT a.account_code, a.account_name, a.account_type, COALESCE(SUM(e.debit_minor - e.credit_minor),0) AS balance
			FROM mda_accounts a JOIN mda_journal_entries e ON e.account_id = a.id JOIN mda_documents d ON d.id = e.document_id
			WHERE ' . self::POSTED . ' AND d.document_date <= :d GROUP BY a.id HAVING balance <> 0 ORDER BY a.account_code'
		);
		$q->execute( array( ':d' => $as_of ) );
		$out = array( 'asset' => array(), 'liability' => array(), 'equity' => array(), 'totals' => array( 'asset' => 0, 'liability' => 0, 'equity' => 0, 'profit' => 0 ) );
		foreach ( $q->fetchAll() as $r ) {
			$bal = (int) $r['balance'];
			switch ( $r['account_type'] ) {
				case 'asset':
					$out['asset'][]           = array( 'code' => $r['account_code'], 'name' => $r['account_name'], 'amount' => $bal );
					$out['totals']['asset']  += $bal;
					break;
				case 'liability':
				case 'equity':
					$out[ $r['account_type'] ][]          = array( 'code' => $r['account_code'], 'name' => $r['account_name'], 'amount' => -$bal );
					$out['totals'][ $r['account_type'] ] += -$bal;
					break;
				default:
					$out['totals']['profit'] += -$bal;
			}
		}
		$out['totals']['equity_with_profit'] = $out['totals']['equity'] + $out['totals']['profit'];
		$out['balanced'] = $out['totals']['asset'] === $out['totals']['liability'] + $out['totals']['equity_with_profit'];
		return $out;
	}

	/**
	 * FIFO aging of customer receivables (role customer → account 1200) or supplier payables (2100).
	 * Buckets by age of the oldest unsettled amount: 0-30, 31-60, 61-90, 90+ days.
	 */
	public static function aging( string $role, string $as_of ): array {
		$code = 'supplier' === $role ? '2100' : '1200';
		$sign = 'supplier' === $role ? -1 : 1;
		$q    = MDA_Database::connection()->prepare(
			'SELECT e.party_id, p.display_name, p.phone, d.document_date, (e.debit_minor - e.credit_minor) AS amount
			FROM mda_journal_entries e JOIN mda_documents d ON d.id = e.document_id JOIN mda_accounts a ON a.id = e.account_id
			JOIN mda_parties p ON p.id = e.party_id
			WHERE a.account_code = :code AND ' . self::POSTED . ' AND d.document_date <= :d AND e.party_id IS NOT NULL
			ORDER BY e.party_id, d.document_date, d.id'
		);
		$q->execute( array( ':code' => $code, ':d' => $as_of ) );
		$parties = array();
		foreach ( $q->fetchAll() as $r ) {
			$pid = (int) $r['party_id'];
			if ( ! isset( $parties[ $pid ] ) ) {
				$parties[ $pid ] = array( 'name' => $r['display_name'], 'phone' => $r['phone'], 'open' => array(), 'credit' => 0 );
			}
			$amount = $sign * (int) $r['amount'];
			if ( $amount > 0 ) {
				// Apply any unapplied credit (e.g. advance payment) first.
				$use                        = min( $amount, $parties[ $pid ]['credit'] );
				$parties[ $pid ]['credit'] -= $use;
				$amount                    -= $use;
				if ( $amount > 0 ) {
					$parties[ $pid ]['open'][] = array( 'date' => $r['document_date'], 'amount' => $amount );
				}
				continue;
			}
			$settle = -$amount;
			foreach ( $parties[ $pid ]['open'] as $i => $item ) {
				if ( $settle <= 0 ) {
					break;
				}
				$use                                     = min( $settle, $item['amount'] );
				$parties[ $pid ]['open'][ $i ]['amount'] -= $use;
				$settle                                 -= $use;
			}
			$parties[ $pid ]['credit'] += $settle;
		}
		$as_of_ts = strtotime( $as_of . ' 00:00:00 UTC' );
		$rows     = array();
		$totals   = array( 'b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0, 'total' => 0 );
		foreach ( $parties as $pid => $p ) {
			$row = array( 'party_id' => $pid, 'name' => $p['name'], 'phone' => $p['phone'], 'b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0, 'credit' => $p['credit'] );
			foreach ( $p['open'] as $item ) {
				if ( $item['amount'] <= 0 ) {
					continue;
				}
				$days   = (int) floor( ( $as_of_ts - strtotime( $item['date'] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
				$bucket = $days <= 30 ? 'b0' : ( $days <= 60 ? 'b30' : ( $days <= 90 ? 'b60' : 'b90' ) );
				$row[ $bucket ] += $item['amount'];
			}
			$row['total'] = $row['b0'] + $row['b30'] + $row['b60'] + $row['b90'];
			if ( 0 === $row['total'] && 0 === $row['credit'] ) {
				continue;
			}
			foreach ( array( 'b0', 'b30', 'b60', 'b90', 'total' ) as $k ) {
				$totals[ $k ] += $row[ $k ];
			}
			$rows[] = $row;
		}
		usort( $rows, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		return array( 'rows' => $rows, 'totals' => $totals );
	}

	/**
	 * Streams rows as an Excel-friendly UTF-8 CSV (with BOM) and exits.
	 */
	public static function stream_csv( string $filename, array $header, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					static function ( $v ) {
						$v = (string) $v;
						// Prevent spreadsheet formula injection.
						return preg_match( '/^[=+\-@\t\r]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
					},
					$row
				)
			);
		}
		fclose( $out );
		exit;
	}
}
