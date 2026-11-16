<?php
/**
 * Manual journals, opening balances, reversals, fiscal periods and year-end closing.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Journal_Service {

	/**
	 * Posts a manual (or opening) journal entered by an accountant.
	 *
	 * @param array $lines [ ['account_id'=>int,'party_id'=>?int,'debit_minor'=>int,'credit_minor'=>int,'memo'=>string], ... ]
	 */
	public static function post_manual( string $date, string $description, array $lines, string $key, string $type = 'manual' ): array {
		if ( ! in_array( $type, array( 'manual', 'opening' ), true ) ) {
			throw new InvalidArgumentException( 'نوع سند دستی معتبر نیست.' );
		}
		$db = MDA_Database::connection();
		foreach ( $lines as $line ) {
			$q = $db->prepare( 'SELECT allow_manual_entry FROM mda_accounts WHERE id = :id AND is_active = 1 AND archived_at IS NULL' );
			$q->execute( array( ':id' => (int) ( $line['account_id'] ?? 0 ) ) );
			$allowed = $q->fetchColumn();
			if ( false === $allowed ) {
				throw new InvalidArgumentException( 'یکی از حساب‌های سند وجود ندارد یا غیرفعال است.' );
			}
			if ( 1 !== (int) $allowed ) {
				throw new InvalidArgumentException( 'این حساب گروه است یا ثبت دستی روی آن مجاز نیست؛ یک حساب معین انتخاب کنید.' );
			}
		}
		return MDA_Ledger_Service::post_document(
			array(
				'document_type'   => $type,
				'idempotency_key' => $key,
				'document_date'   => $date,
				'description'     => $description,
				'source_type'     => $type,
			),
			$lines
		);
	}

	/**
	 * Voids a posted document by posting its mirror image on $date and linking both.
	 */
	public static function reverse( int $document_id, string $date, string $reason ): array {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new InvalidArgumentException( 'دلیل ابطال سند الزامی است.' );
		}
		return MDA_Database::transaction(
			static function ( PDO $db ) use ( $document_id, $date, $reason ): array {
				$q = $db->prepare( 'SELECT * FROM mda_documents WHERE id = :id' );
				$q->execute( array( ':id' => $document_id ) );
				$doc = $q->fetch();
				if ( ! $doc || 'posted' !== $doc['document_status'] ) {
					throw new RuntimeException( 'فقط سند قطعی و باطل‌نشده قابل ابطال است.' );
				}
				if ( ! empty( $doc['reversal_document_id'] ) || 'reversal' === $doc['document_type'] ) {
					throw new RuntimeException( 'این سند قبلاً برگشت خورده یا خودش سند برگشتی است.' );
				}
				$guarded = array( 'sale', 'purchase', 'sales_return', 'purchase_return', 'backorder_cogs' );
				if ( in_array( $doc['document_type'], $guarded, true ) ) {
					throw new RuntimeException( 'اسناد فروش، خرید و مرجوعی به انبار و تفصیلی‌ها گره خورده‌اند؛ برای اصلاح آن‌ها از مرجوعی یا سند اصلاحی استفاده کنید.' );
				}
				$l = $db->prepare( 'SELECT account_id, party_id, debit_minor, credit_minor, memo FROM mda_journal_entries WHERE document_id = :id ORDER BY entry_number' );
				$l->execute( array( ':id' => $document_id ) );
				$lines = array();
				foreach ( $l->fetchAll() as $row ) {
					$lines[] = array(
						'account_id'   => (int) $row['account_id'],
						'party_id'     => $row['party_id'] ? (int) $row['party_id'] : null,
						'debit_minor'  => (int) $row['credit_minor'],
						'credit_minor' => (int) $row['debit_minor'],
						'memo'         => 'برگشت: ' . (string) $row['memo'],
					);
				}
				$posted = MDA_Ledger_Service::post_document(
					array(
						'document_type'   => 'reversal',
						'idempotency_key' => 'reversal-of-' . $document_id,
						'document_date'   => $date,
						'currency_code'   => $doc['currency_code'],
						'party_id'        => $doc['party_id'],
						'source_type'     => 'reversal',
						'source_id'       => (string) $document_id,
						'description'     => 'ابطال سند ' . $doc['document_number'] . ' — ' . $reason,
					),
					$lines
				);
				$u = $db->prepare( "UPDATE mda_documents SET document_status = 'voided', voided_at = :now, reversal_document_id = :rev WHERE id = :id" );
				$u->execute( array( ':now' => gmdate( 'Y-m-d H:i:s' ), ':rev' => $posted['document_id'], ':id' => $document_id ) );
				self::audit( $db, 'document_reversed', (string) $document_id, array( 'reversal_document_id' => $posted['document_id'], 'reason' => $reason ) );
				return $posted;
			}
		);
	}

	public static function create_period( string $name, string $starts_on, string $ends_on ): int {
		foreach ( array( $starts_on, $ends_on ) as $d ) {
			$o = DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
			if ( ! $o || $o->format( 'Y-m-d' ) !== $d ) {
				throw new InvalidArgumentException( 'تاریخ دوره مالی معتبر نیست.' );
			}
		}
		if ( $ends_on < $starts_on || '' === trim( $name ) ) {
			throw new InvalidArgumentException( 'نام یا بازه دوره مالی معتبر نیست.' );
		}
		return MDA_Database::transaction(
			static function ( PDO $db ) use ( $name, $starts_on, $ends_on ): int {
				$o = $db->prepare( 'SELECT 1 FROM mda_financial_periods WHERE starts_on <= :e AND ends_on >= :s' );
				$o->execute( array( ':s' => $starts_on, ':e' => $ends_on ) );
				if ( $o->fetchColumn() ) {
					throw new RuntimeException( 'این بازه با یک دوره مالی دیگر هم‌پوشانی دارد.' );
				}
				$q = $db->prepare( "INSERT INTO mda_financial_periods (period_name, starts_on, ends_on, status) VALUES (:n, :s, :e, 'open')" );
				$q->execute( array( ':n' => sanitize_text_field( $name ), ':s' => $starts_on, ':e' => $ends_on ) );
				return (int) $db->lastInsertId();
			}
		);
	}

	public static function periods(): array {
		return MDA_Database::connection()->query( 'SELECT * FROM mda_financial_periods ORDER BY starts_on DESC' )->fetchAll();
	}

	/**
	 * Year-end: moves every income/expense balance of the period into retained earnings (3200),
	 * then locks the period. Idempotent per period.
	 */
	public static function close_period( int $period_id, bool $with_closing_entry = true ): array {
		return MDA_Database::transaction(
			static function ( PDO $db ) use ( $period_id, $with_closing_entry ): array {
				$q = $db->prepare( 'SELECT * FROM mda_financial_periods WHERE id = :id' );
				$q->execute( array( ':id' => $period_id ) );
				$period = $q->fetch();
				if ( ! $period ) {
					throw new RuntimeException( 'دوره مالی پیدا نشد.' );
				}
				if ( 'closed' === $period['status'] ) {
					throw new RuntimeException( 'این دوره قبلاً بسته شده است.' );
				}
				$pending = (int) $db->query( "SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing','failed')" )->fetchColumn();
				if ( $pending > 0 ) {
					throw new RuntimeException( 'پیش از بستن دوره، رویدادهای صف حسابداری (' . $pending . ' مورد) باید پردازش یا تعیین تکلیف شوند.' );
				}
				$document = null;
				if ( $with_closing_entry ) {
					$b = $db->prepare(
						"SELECT a.id, a.account_type, SUM(e.debit_minor) - SUM(e.credit_minor) AS balance
						FROM mda_journal_entries e JOIN mda_documents d ON d.id = e.document_id JOIN mda_accounts a ON a.id = e.account_id
						WHERE d.document_status IN ('posted','voided') AND d.document_date BETWEEN :s AND :e AND a.account_type IN ('income','expense')
						GROUP BY a.id HAVING balance <> 0"
					);
					$b->execute( array( ':s' => $period['starts_on'], ':e' => $period['ends_on'] ) );
					$lines = array();
					$net   = 0;
					foreach ( $b->fetchAll() as $row ) {
						$bal  = (int) $row['balance'];
						$net += $bal;
						$lines[] = array(
							'account_id'   => (int) $row['id'],
							'debit_minor'  => $bal < 0 ? -$bal : 0,
							'credit_minor' => $bal > 0 ? $bal : 0,
							'memo'         => 'بستن حساب موقت',
						);
					}
					if ( $lines ) {
						$retained = (int) $db->query( "SELECT id FROM mda_accounts WHERE account_code = '3200'" )->fetchColumn();
						if ( $net > 0 ) {
							$lines[] = array( 'account_id' => $retained, 'debit_minor' => $net, 'credit_minor' => 0, 'memo' => 'زیان دوره' );
						} elseif ( $net < 0 ) {
							$lines[] = array( 'account_id' => $retained, 'debit_minor' => 0, 'credit_minor' => -$net, 'memo' => 'سود دوره' );
						}
						$document = MDA_Ledger_Service::post_document(
							array(
								'document_type'   => 'closing',
								'idempotency_key' => 'period-closing-' . $period_id . '-' . ( 1 + (int) $db->query( "SELECT COUNT(*) FROM mda_documents WHERE document_type = 'closing' AND source_id = '" . (int) $period_id . "'" )->fetchColumn() ),
								'document_date'   => $period['ends_on'],
								'source_type'     => 'period',
								'source_id'       => (string) $period_id,
								'description'     => 'سند اختتامیه حساب‌های موقت ' . $period['period_name'],
							),
							$lines
						);
					}
				}
				$u = $db->prepare( "UPDATE mda_financial_periods SET status = 'closed', closed_by = :u, closed_at = :now WHERE id = :id" );
				$u->execute( array( ':u' => get_current_user_id() ?: null, ':now' => gmdate( 'Y-m-d H:i:s' ), ':id' => $period_id ) );
				self::audit( $db, 'period_closed', (string) $period_id, array( 'closing_document' => $document['document_id'] ?? null ) );
				return array( 'period_id' => $period_id, 'closing_document_id' => $document['document_id'] ?? null );
			}
		);
	}

	public static function reopen_period( int $period_id ): void {
		MDA_Database::transaction(
			static function ( PDO $db ) use ( $period_id ): void {
				$u = $db->prepare( "UPDATE mda_financial_periods SET status = 'open', closed_by = NULL, closed_at = NULL WHERE id = :id AND status = 'closed'" );
				$u->execute( array( ':id' => $period_id ) );
				if ( 1 !== $u->rowCount() ) {
					throw new RuntimeException( 'دوره بسته‌ای با این شناسه پیدا نشد.' );
				}
				self::audit( $db, 'period_reopened', (string) $period_id, array() );
			}
		);
	}

	public static function documents( array $filters, int $limit = 200 ): array {
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['from'] ) ) {
			$where[]         = 'd.document_date >= :from';
			$params[':from'] = $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]       = 'd.document_date <= :to';
			$params[':to'] = $filters['to'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[]         = 'd.document_type = :type';
			$params[':type'] = $filters['type'];
		}
		$sql = 'SELECT d.*, COALESCE(SUM(e.debit_minor),0) AS total_minor FROM mda_documents d LEFT JOIN mda_journal_entries e ON e.document_id = d.id WHERE '
			. implode( ' AND ', $where ) . ' GROUP BY d.id ORDER BY d.document_date DESC, d.id DESC LIMIT ' . max( 1, min( 1000, $limit ) );
		$q = MDA_Database::connection()->prepare( $sql );
		$q->execute( $params );
		return $q->fetchAll();
	}

	public static function document_lines( int $document_id ): array {
		$q = MDA_Database::connection()->prepare(
			'SELECT e.*, a.account_code, a.account_name, p.display_name AS party_name FROM mda_journal_entries e
			JOIN mda_accounts a ON a.id = e.account_id LEFT JOIN mda_parties p ON p.id = e.party_id
			WHERE e.document_id = :id ORDER BY e.entry_number'
		);
		$q->execute( array( ':id' => $document_id ) );
		return $q->fetchAll();
	}

	public static function postable_accounts(): array {
		return MDA_Database::connection()->query( 'SELECT id, account_code, account_name, is_control FROM mda_accounts WHERE is_active = 1 AND archived_at IS NULL AND allow_manual_entry = 1 ORDER BY account_code' )->fetchAll();
	}

	private static function audit( PDO $db, string $action, string $id, array $after ): void {
		$db->prepare( "INSERT INTO mda_audit_logs (user_id, action, object_type, object_id, after_json, created_at) VALUES (:u, :a, 'document', :id, :j, :now)" )
			->execute( array( ':u' => get_current_user_id() ?: null, ':a' => $action, ':id' => $id, ':j' => wp_json_encode( $after ), ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
