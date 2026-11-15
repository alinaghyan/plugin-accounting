<?php
defined( 'ABSPATH' ) || exit;

/**
 * ثبت سند دوطرفه قطعی در یک تراکنش SQLite.
 * این سرویس هنوز از UI یا رویدادهای WooCommerce فراخوانی نمی‌شود.
 */
final class MDA_Ledger_Service {
	/**
	 * @param array $document اطلاعات سربرگ سند.
	 * @param array $lines ردیف‌های بدهکار/بستانکار.
	 * @return array{document_id:int,document_number:string,duplicate:bool}
	 */
	public static function post_document( array $document, array $lines ): array {
		$document = self::validate_document( $document );
		$lines = self::validate_lines( $lines );

		$debit_total = 0;
		$credit_total = 0;
		foreach ( $lines as $line ) {
			$debit_total = self::safe_add( $debit_total, $line['debit_minor'] );
			$credit_total = self::safe_add( $credit_total, $line['credit_minor'] );
		}
		if ( $debit_total < 1 || $debit_total !== $credit_total ) {
			throw new InvalidArgumentException( 'جمع بدهکار و بستانکار باید برابر و بزرگ‌تر از صفر باشد.' );
		}

		$payload_hash = hash( 'sha256', wp_json_encode( array( $document, $lines ) ) );
		$now = gmdate( 'Y-m-d H:i:s' );

		return MDA_Database::transaction(
			static function ( PDO $pdo ) use ( $document, $lines, $payload_hash, $now ): array {
				$existing = $pdo->prepare( 'SELECT id, document_number, payload_hash FROM mda_documents WHERE idempotency_key = :key LIMIT 1' );
				$existing->execute( array( ':key' => $document['idempotency_key'] ) );
				$found = $existing->fetch();

				if ( $found ) {
					if ( ! hash_equals( (string) $found['payload_hash'], $payload_hash ) ) {
						throw new RuntimeException( 'کلید idempotency قبلاً برای محتوای متفاوتی استفاده شده است.' );
					}
					return array(
						'document_id' => (int) $found['id'],
						'document_number' => (string) $found['document_number'],
						'duplicate' => true,
					);
				}

				$closed = $pdo->prepare(
					"SELECT id FROM mda_financial_periods WHERE status = 'closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1"
				);
				$closed->execute( array( ':date' => $document['document_date'] ) );
				if ( $closed->fetchColumn() ) {
					throw new RuntimeException( 'ثبت سند در دوره مالی بسته‌شده مجاز نیست.' );
				}

				foreach ( $lines as $line ) {
					$account = $pdo->prepare( 'SELECT id,is_control FROM mda_accounts WHERE id = :id AND is_active = 1 AND archived_at IS NULL LIMIT 1' );
					$account->execute( array( ':id' => $line['account_id'] ) );
					$account_row = $account->fetch();
					if ( ! $account_row ) {
						throw new InvalidArgumentException( 'یکی از حساب‌های سند وجود ندارد یا غیرفعال است.' );
					}
					if ( 1 === (int) $account_row['is_control'] ) {
						if ( empty( $line['party_id'] ) ) {
							throw new InvalidArgumentException( 'ثبت روی حساب کنترلی بدون تفصیلی طرف‌حساب مجاز نیست.' );
						}
						$party_role = $pdo->prepare( 'SELECT 1 FROM mda_party_control_accounts m JOIN mda_party_roles r ON r.role_key=m.role_key WHERE m.account_id=:account AND r.party_id=:party LIMIT 1' );
						$party_role->execute( array( ':account' => $line['account_id'], ':party' => $line['party_id'] ) );
						if ( ! $party_role->fetchColumn() ) {
							throw new InvalidArgumentException( 'طرف‌حساب نقش متناظر با حساب کنترلی را ندارد یا حساب به آن نقش متصل نیست.' );
						}
					}
				}

				$document_number = 'MDA-' . gmdate( 'Ymd' ) . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
				$insert_document = $pdo->prepare(
					'INSERT INTO mda_documents
					(document_number, document_type, document_status, party_id, source_type, source_id, idempotency_key, payload_hash, currency_code, amount_scale, document_date, description, created_by, created_at, posted_at)
					VALUES
					(:number, :type, :status, :party, :source_type, :source_id, :idempotency, :payload_hash, :currency, :scale, :date, :description, :created_by, :created_at, :posted_at)'
				);
				$insert_document->execute( array(
					':number' => $document_number,
					':type' => $document['document_type'],
					':status' => 'posted',
					':party' => $document['party_id'],
					':source_type' => $document['source_type'],
					':source_id' => $document['source_id'],
					':idempotency' => $document['idempotency_key'],
					':payload_hash' => $payload_hash,
					':currency' => $document['currency_code'],
					':scale' => $document['amount_scale'],
					':date' => $document['document_date'],
					':description' => $document['description'],
					':created_by' => get_current_user_id() ?: null,
					':created_at' => $now,
					':posted_at' => $now,
				) );
				$document_id = (int) $pdo->lastInsertId();

				$insert_line = $pdo->prepare(
					'INSERT INTO mda_journal_entries
					(document_id, entry_number, account_id, party_id, debit_minor, credit_minor, memo, created_at)
					VALUES
					(:document_id, :entry_number, :account_id, :party_id, :debit, :credit, :memo, :created_at)'
				);
				foreach ( $lines as $index => $line ) {
					$insert_line->execute( array(
						':document_id' => $document_id,
						':entry_number' => $index + 1,
						':account_id' => $line['account_id'],
						':party_id' => $line['party_id'],
						':debit' => $line['debit_minor'],
						':credit' => $line['credit_minor'],
						':memo' => $line['memo'],
						':created_at' => $now,
					) );
				}

				return array(
					'document_id' => $document_id,
					'document_number' => $document_number,
					'duplicate' => false,
				);
			}
		);
	}

	private static function validate_document( array $document ): array {
		$type = isset( $document['document_type'] ) ? sanitize_key( $document['document_type'] ) : '';
		$key = isset( $document['idempotency_key'] ) ? trim( (string) $document['idempotency_key'] ) : '';
		$date = isset( $document['document_date'] ) ? (string) $document['document_date'] : '';
		$currency = isset( $document['currency_code'] ) ? strtoupper( (string) $document['currency_code'] ) : 'IRR';
		$scale = isset( $document['amount_scale'] ) ? (int) $document['amount_scale'] : 1;

		$date_object = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( '' === $type || strlen( $key ) < 8 || strlen( $key ) > 191 || ! $date_object || $date_object->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidArgumentException( 'نوع سند، کلید یکتا یا تاریخ سند معتبر نیست.' );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || $scale < 1 || $scale > 1000000 ) {
			throw new InvalidArgumentException( 'واحد پول یا ضریب اعشار معتبر نیست.' );
		}

		return array(
			'document_type' => $type,
			'idempotency_key' => $key,
			'document_date' => $date,
			'currency_code' => $currency,
			'amount_scale' => $scale,
			'party_id' => ! empty( $document['party_id'] ) ? absint( $document['party_id'] ) : null,
			'source_type' => isset( $document['source_type'] ) ? sanitize_key( $document['source_type'] ) : null,
			'source_id' => isset( $document['source_id'] ) ? sanitize_text_field( (string) $document['source_id'] ) : null,
			'description' => isset( $document['description'] ) ? sanitize_textarea_field( (string) $document['description'] ) : null,
		);
	}

	private static function validate_lines( array $lines ): array {
		if ( count( $lines ) < 2 || count( $lines ) > 500 ) {
			throw new InvalidArgumentException( 'سند باید حداقل دو و حداکثر ۵۰۰ ردیف داشته باشد.' );
		}
		$clean = array();
		foreach ( $lines as $line ) {
			$account_id = isset( $line['account_id'] ) ? absint( $line['account_id'] ) : 0;
			$debit = isset( $line['debit_minor'] ) ? $line['debit_minor'] : 0;
			$credit = isset( $line['credit_minor'] ) ? $line['credit_minor'] : 0;
			if ( ! is_int( $debit ) || ! is_int( $credit ) || $debit < 0 || $credit < 0 || $account_id < 1 ) {
				throw new InvalidArgumentException( 'مبلغ‌ها باید عدد صحیح نامنفی و حساب معتبر باشد.' );
			}
			if ( ( $debit > 0 && $credit > 0 ) || ( 0 === $debit && 0 === $credit ) ) {
				throw new InvalidArgumentException( 'هر ردیف باید فقط بدهکار یا فقط بستانکار باشد.' );
			}
			$clean[] = array(
				'account_id' => $account_id,
				'party_id' => ! empty( $line['party_id'] ) ? absint( $line['party_id'] ) : null,
				'debit_minor' => $debit,
				'credit_minor' => $credit,
				'memo' => isset( $line['memo'] ) ? sanitize_text_field( (string) $line['memo'] ) : null,
			);
		}
		return $clean;
	}

	private static function safe_add( int $left, int $right ): int {
		if ( $right > PHP_INT_MAX - $left ) {
			throw new OverflowException( 'مجموع مبلغ سند از ظرفیت عدد صحیح بیشتر است.' );
		}
		return $left + $right;
	}
}
