<?php
/**
 * Screens added on top of the original admin: journal vouchers, ledgers & statements,
 * inventory (opening stock, stock count, backorders) and settings (fiscal periods, backups, Moodian data).
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Admin_Pages {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		$handlers = array(
			'mda_post_journal'      => 'handle_post_journal',
			'mda_void_document'     => 'handle_void_document',
			'mda_inventory_adjust'  => 'handle_inventory_adjust',
			'mda_save_settings'     => 'handle_save_settings',
			'mda_create_period'     => 'handle_create_period',
			'mda_close_period'      => 'handle_close_period',
			'mda_reopen_period'     => 'handle_reopen_period',
			'mda_backup_create'     => 'handle_backup_create',
			'mda_backup_download'   => 'handle_backup_download',
			'mda_reclaim_database'  => 'handle_reclaim',
			'mda_export_csv'        => 'handle_export_csv',
		);
		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	public static function menu(): void {
		$p = MDA_Admin_Shell::HIDDEN_PARENT;
		add_submenu_page( $p, 'سند حسابداری', 'سند حسابداری', MDA_Capabilities::for_page( 'mda-journal' ), 'mda-journal', array( __CLASS__, 'render_journal' ) );
		add_submenu_page( $p, 'دفاتر و تراز', 'دفاتر و تراز', MDA_Capabilities::for_page( 'mda-ledgers' ), 'mda-ledgers', array( __CLASS__, 'render_ledgers' ) );
		add_submenu_page( $p, 'انبار و سفارش‌های معوق', 'انبار', MDA_Capabilities::for_page( 'mda-inventory' ), 'mda-inventory', array( __CLASS__, 'render_inventory' ) );
		add_submenu_page( $p, 'تنظیمات حسابداری', 'تنظیمات', MDA_Capabilities::for_page( 'mda-settings' ), 'mda-settings', array( __CLASS__, 'render_settings' ) );
	}

	/* ---------------------------------------------------------------- helpers */

	private static function guard(): void {
		if ( ! MDA_Capabilities::user_can_action() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'malidev-accounting' ) );
		}
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler verifies its nonce before reading.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function get( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/** Parses "۱٬۲۵۰٬۰۰۰" / "1,250,000" into an integer amount. */
	public static function amount( string $raw ): int {
		$clean = preg_replace( '/[^\d]/', '', MDA_Jalali::latin_digits( $raw ) );
		if ( '' === $clean ) {
			return 0;
		}
		if ( strlen( $clean ) > 18 ) {
			throw new InvalidArgumentException( 'مبلغ وارد شده بیش از حد بزرگ است.' );
		}
		return (int) $clean;
	}

	private static function date_input( string $raw, string $label ): string {
		$date = MDA_Jalali::normalize_input( $raw );
		if ( null === $date ) {
			throw new InvalidArgumentException( 'تاریخ ' . $label . ' معتبر نیست؛ به شکل ۱۴۰۵/۰۸/۲۵ وارد کنید.' );
		}
		return $date;
	}

	private static function redirect( string $page, string $message, bool $error = false, array $args = array() ): void {
		$url = add_query_arg( array_merge( array( 'page' => $page, $error ? 'mda_error' : 'mda_notice' => rawurlencode( $message ) ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function notices(): void {
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $key => $type ) {
			$v = self::get( $key );
			if ( '' !== $v ) {
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( rawurldecode( $v ) ) . '</p></div>';
			}
		}
	}

	private static function money( int $v ): string {
		$s = number_format( abs( $v ) );
		return ( $v < 0 ? '(' . $s . ')' : $s );
	}

	private static function jdate( ?string $g ): string {
		return MDA_Settings::get( 'jalali_dates' ) ? MDA_Jalali::format( $g ) : (string) $g;
	}

	private static function today_input(): string {
		return MDA_Settings::get( 'jalali_dates' ) ? MDA_Jalali::format( current_time( 'Y-m-d' ), false ) : current_time( 'Y-m-d' );
	}

	private static function db_ready(): bool {
		try {
			MDA_Database::connection();
			return true;
		} catch ( Throwable $e ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			if ( $e instanceof MDA_Foreign_Database_Exception && current_user_can( MDA_Capabilities::MANAGE ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'mda_reclaim_database' );
				echo '<input type="hidden" name="action" value="mda_reclaim_database"><button class="button button-primary">تأیید انتقال دیتابیس به این نصب</button></form>';
			}
			echo '</div>';
			return false;
		}
	}

	private static function range_from_request(): array {
		$from = MDA_Jalali::normalize_input( self::get( 'from' ) );
		$to   = MDA_Jalali::normalize_input( self::get( 'to' ) );
		if ( ! $from || ! $to ) {
			list( $jy ) = MDA_Jalali::to_jalali( (int) current_time( 'Y' ), (int) current_time( 'n' ), (int) current_time( 'j' ) );
			list( $from, $to ) = MDA_Jalali::year_range( $jy );
		}
		return array( $from, $to );
	}

	private static function range_form( string $page, array $extra = array() ): void {
		list( $from, $to ) = self::range_from_request();
		echo '<form method="get" class="mda-filter"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<label>از <input class="mda-jdate" name="from" value="' . esc_attr( MDA_Jalali::format( $from, false ) ) . '" size="11"></label> ';
		echo '<label>تا <input class="mda-jdate" name="to" value="' . esc_attr( MDA_Jalali::format( $to, false ) ) . '" size="11"></label> ';
		echo '<button class="button">نمایش</button></form>';
	}

	/* ---------------------------------------------------------------- journal */

	public static function render_journal(): void {
		self::guard();
		if ( ! self::db_ready() ) {
			return;
		}
		$view     = absint( self::get( 'document_id' ) );
		$accounts = MDA_Journal_Service::postable_accounts();
		$parties  = MDA_Party_Service::search( '', 500 );
		echo '<div class="wrap" dir="rtl"><h1>سند حسابداری</h1>';
		self::notices();
		if ( $view ) {
			self::render_document( $view );
		}
		echo '<div class="mda-card"><h2>ثبت سند دستی / افتتاحیه</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="mda-journal-form">';
		wp_nonce_field( 'mda_post_journal' );
		echo '<input type="hidden" name="action" value="mda_post_journal"><input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		echo '<p><label>تاریخ <input class="mda-jdate" name="document_date" value="' . esc_attr( self::today_input() ) . '" required size="11"></label> ';
		echo '<label>نوع <select name="document_type"><option value="manual">سند عمومی</option><option value="opening">سند افتتاحیه (مانده اول دوره)</option></select></label> ';
		echo '<label>شرح <input name="description" class="regular-text" required></label></p>';
		echo '<table class="widefat mda-journal-lines"><thead><tr><th>حساب</th><th>طرف‌حساب (برای دریافتنی/پرداختنی)</th><th>بدهکار</th><th>بستانکار</th><th>شرح ردیف</th></tr></thead><tbody>';
		for ( $i = 0; $i < 6; $i++ ) {
			echo '<tr><td><select name="lines[' . (int) $i . '][account_id]"><option value="">—</option>';
			foreach ( $accounts as $a ) {
				echo '<option value="' . (int) $a['id'] . '">' . esc_html( $a['account_code'] . ' — ' . $a['account_name'] ) . '</option>';
			}
			echo '</select></td><td><select name="lines[' . (int) $i . '][party_id]"><option value="">—</option>';
			foreach ( $parties as $p ) {
				echo '<option value="' . (int) $p['id'] . '">' . esc_html( $p['display_name'] ) . '</option>';
			}
			echo '</select></td><td><input class="mda-amount" name="lines[' . (int) $i . '][debit]" inputmode="numeric"></td><td><input class="mda-amount" name="lines[' . (int) $i . '][credit]" inputmode="numeric"></td><td><input name="lines[' . (int) $i . '][memo]"></td></tr>';
		}
		echo '</tbody><tfoot><tr><th colspan="2">جمع</th><th class="mda-sum-debit">0</th><th class="mda-sum-credit">0</th><th class="mda-sum-diff"></th></tr></tfoot></table>';
		echo '<p class="description">ردیف‌های خالی نادیده گرفته می‌شوند. سند فقط در صورت برابر بودن جمع بدهکار و بستانکار ثبت می‌شود. برای مانده‌های اول دوره، طرف مقابل را حساب «۳۹۰۰ تراز افتتاحیه» بگیرید.</p>';
		echo '<p><button class="button button-primary">ثبت قطعی سند</button></p></form></div>';

		$docs = MDA_Journal_Service::documents( array( 'from' => '', 'to' => '' ), 100 );
		echo '<div class="mda-card"><h2>آخرین اسناد</h2><table class="widefat striped"><thead><tr><th>شماره</th><th>تاریخ</th><th>نوع</th><th>شرح</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
		foreach ( $docs as $d ) {
			$url = add_query_arg( array( 'page' => 'mda-journal', 'document_id' => (int) $d['id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $d['document_number'] ) . '</a></td><td>' . esc_html( self::jdate( $d['document_date'] ) ) . '</td><td>' . esc_html( self::type_label( $d['document_type'] ) ) . '</td><td>' . esc_html( (string) $d['description'] ) . '</td><td>' . esc_html( self::money( (int) $d['total_minor'] ) ) . '</td><td>' . esc_html( 'voided' === $d['document_status'] ? 'باطل‌شده' : 'قطعی' ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function render_document( int $id ): void {
		$q = MDA_Database::connection()->prepare( 'SELECT * FROM mda_documents WHERE id = :id' );
		$q->execute( array( ':id' => $id ) );
		$doc = $q->fetch();
		if ( ! $doc ) {
			return;
		}
		echo '<div class="mda-card"><h2>سند ' . esc_html( $doc['document_number'] ) . ' — ' . esc_html( self::type_label( $doc['document_type'] ) ) . '</h2><p>' . esc_html( self::jdate( $doc['document_date'] ) ) . ' · ' . esc_html( (string) $doc['description'] ) . '</p>';
		echo '<table class="widefat"><thead><tr><th>#</th><th>حساب</th><th>طرف‌حساب</th><th>بدهکار</th><th>بستانکار</th><th>شرح</th></tr></thead><tbody>';
		foreach ( MDA_Journal_Service::document_lines( $id ) as $l ) {
			echo '<tr><td>' . (int) $l['entry_number'] . '</td><td>' . esc_html( $l['account_code'] . ' ' . $l['account_name'] ) . '</td><td>' . esc_html( (string) $l['party_name'] ) . '</td><td>' . esc_html( self::money( (int) $l['debit_minor'] ) ) . '</td><td>' . esc_html( self::money( (int) $l['credit_minor'] ) ) . '</td><td>' . esc_html( (string) $l['memo'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( 'posted' === $doc['document_status'] && empty( $doc['reversal_document_id'] ) && current_user_can( MDA_Capabilities::VOID_DOCUMENTS ) && in_array( $doc['document_type'], array( 'manual', 'opening', 'cash', 'expense', 'transfer', 'stock_count', 'opening_stock' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
			wp_nonce_field( 'mda_void_document_' . $id );
			echo '<input type="hidden" name="action" value="mda_void_document"><input type="hidden" name="document_id" value="' . (int) $id . '">';
			echo '<label>تاریخ برگشت <input class="mda-jdate" name="date" value="' . esc_attr( self::today_input() ) . '" size="11"></label> <label>دلیل <input name="reason" required></label> ';
			echo '<button class="button button-link-delete" onclick="return confirm(\'سند برگشتی ثبت و این سند باطل می‌شود. ادامه می‌دهید؟\')">ابطال با سند برگشتی</button></form>';
		} elseif ( 'voided' === $doc['document_status'] ) {
			echo '<p class="mda-muted">این سند باطل شده و سند برگشتی آن ثبت است.</p>';
		}
		echo '</div>';
	}

	public static function type_label( string $type ): string {
		$labels = array(
			'manual' => 'سند عمومی', 'opening' => 'افتتاحیه', 'reversal' => 'برگشتی', 'closing' => 'اختتامیه', 'sale' => 'فروش', 'purchase' => 'خرید',
			'sales_return' => 'مرجوعی فروش', 'purchase_return' => 'مرجوعی خرید', 'backorder_cogs' => 'بهای تمام‌شده سفارشی', 'opening_stock' => 'موجودی افتتاحیه',
			'stock_count' => 'انبارگردانی', 'sales_receipt' => 'دریافت از مشتری', 'purchase_payment' => 'پرداخت به تأمین‌کننده',
		);
		return $labels[ $type ] ?? $type;
	}

	public static function handle_post_journal(): void {
		self::guard();
		check_admin_referer( 'mda_post_journal' );
		try {
			$date  = self::date_input( self::post( 'document_date' ), 'سند' );
			$lines = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
			foreach ( (array) ( $_POST['lines'] ?? array() ) as $row ) {
				$row     = is_array( $row ) ? wp_unslash( $row ) : array();
				$account = absint( $row['account_id'] ?? 0 );
				$debit   = self::amount( (string) ( $row['debit'] ?? '' ) );
				$credit  = self::amount( (string) ( $row['credit'] ?? '' ) );
				if ( ! $account && ! $debit && ! $credit ) {
					continue;
				}
				$lines[] = array(
					'account_id'   => $account,
					'party_id'     => absint( $row['party_id'] ?? 0 ) ?: null,
					'debit_minor'  => $debit,
					'credit_minor' => $credit,
					'memo'         => sanitize_text_field( (string) ( $row['memo'] ?? '' ) ),
				);
			}
			$type   = 'opening' === self::post( 'document_type' ) ? 'opening' : 'manual';
			$posted = MDA_Journal_Service::post_manual( $date, self::post( 'description' ), $lines, 'manual-' . self::post( 'request_key' ), $type );
			self::redirect( 'mda-journal', 'سند ' . $posted['document_number'] . ' ثبت شد.', false, array( 'document_id' => $posted['document_id'] ) );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-journal', $e->getMessage(), true );
		}
	}

	public static function handle_void_document(): void {
		self::guard();
		$id = absint( self::post( 'document_id' ) );
		check_admin_referer( 'mda_void_document_' . $id );
		try {
			$rev = MDA_Journal_Service::reverse( $id, self::date_input( self::post( 'date' ), 'برگشت' ), self::post( 'reason' ) );
			self::redirect( 'mda-journal', 'سند برگشتی ' . $rev['document_number'] . ' ثبت شد.', false, array( 'document_id' => $rev['document_id'] ) );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-journal', $e->getMessage(), true, array( 'document_id' => $id ) );
		}
	}

	/* ---------------------------------------------------------------- ledgers */

	public static function render_ledgers(): void {
		self::guard();
		if ( ! self::db_ready() ) {
			return;
		}
		$tab  = self::get( 'tab' ) ?: 'trial';
		$tabs = array( 'trial' => 'تراز آزمایشی', 'ledger' => 'دفتر معین', 'balance' => 'ترازنامه', 'ar' => 'سنی بدهکاران', 'ap' => 'سنی بستانکاران' );
		echo '<div class="wrap" dir="rtl"><h1>دفاتر و صورت‌ها</h1>';
		self::notices();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'mda-ledgers', 'tab' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="mda-card">';
		list( $from, $to ) = self::range_from_request();
		$export = static function ( string $report, array $args = array() ): void {
			$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mda_export_csv', 'report' => $report, 'from' => self::get( 'from' ), 'to' => self::get( 'to' ) ), $args ), admin_url( 'admin-post.php' ) ), 'mda_export_csv' );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">خروجی اکسل (CSV)</a></p>';
		};
		switch ( $tab ) {
			case 'ledger':
				$account = absint( self::get( 'account_id' ) );
				$party   = absint( self::get( 'party_id' ) );
				echo '<form method="get" class="mda-filter"><input type="hidden" name="page" value="mda-ledgers"><input type="hidden" name="tab" value="ledger"><select name="account_id">';
				foreach ( MDA_Database::connection()->query( 'SELECT id, account_code, account_name FROM mda_accounts ORDER BY account_code' )->fetchAll() as $a ) {
					echo '<option value="' . (int) $a['id'] . '"' . selected( $account, (int) $a['id'], false ) . '>' . esc_html( $a['account_code'] . ' — ' . $a['account_name'] ) . '</option>';
				}
				echo '</select> <select name="party_id"><option value="0">همه طرف‌حساب‌ها</option>';
				foreach ( MDA_Party_Service::search( '', 500 ) as $p ) {
					echo '<option value="' . (int) $p['id'] . '"' . selected( $party, (int) $p['id'], false ) . '>' . esc_html( $p['display_name'] ) . '</option>';
				}
				echo '</select> <input class="mda-jdate" name="from" value="' . esc_attr( MDA_Jalali::format( $from, false ) ) . '" size="11"> تا <input class="mda-jdate" name="to" value="' . esc_attr( MDA_Jalali::format( $to, false ) ) . '" size="11"> <button class="button">نمایش</button></form>';
				if ( $account ) {
					$l = MDA_Ledger_Report_Service::account_ledger( $account, $from, $to, $party );
					$export( 'ledger', array( 'account_id' => $account, 'party_id' => $party ) );
					echo '<table class="widefat striped"><thead><tr><th>تاریخ</th><th>سند</th><th>شرح</th><th>طرف‌حساب</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>';
					echo '<tr><td colspan="6">مانده از قبل</td><td>' . esc_html( self::money( $l['opening'] ) ) . '</td></tr>';
					foreach ( $l['rows'] as $r ) {
						echo '<tr><td>' . esc_html( self::jdate( $r['document_date'] ) ) . '</td><td><a href="' . esc_url( add_query_arg( array( 'page' => 'mda-journal', 'document_id' => (int) $r['document_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $r['document_number'] ) . '</a></td><td>' . esc_html( (string) ( $r['memo'] ?: $r['description'] ) ) . '</td><td>' . esc_html( (string) $r['party_name'] ) . '</td><td>' . esc_html( self::money( (int) $r['debit_minor'] ) ) . '</td><td>' . esc_html( self::money( (int) $r['credit_minor'] ) ) . '</td><td>' . esc_html( self::money( (int) $r['balance'] ) ) . '</td></tr>';
					}
					echo '</tbody></table>';
				}
				break;
			case 'balance':
				$bs = MDA_Ledger_Report_Service::balance_sheet( $to );
				echo '<form method="get" class="mda-filter"><input type="hidden" name="page" value="mda-ledgers"><input type="hidden" name="tab" value="balance"><label>تا تاریخ <input class="mda-jdate" name="to" value="' . esc_attr( MDA_Jalali::format( $to, false ) ) . '" size="11"></label><input type="hidden" name="from" value="' . esc_attr( MDA_Jalali::format( $from, false ) ) . '"> <button class="button">نمایش</button></form>';
				echo '<div class="mda-grid-2"><div><h3>دارایی‌ها</h3><table class="widefat">';
				foreach ( $bs['asset'] as $r ) {
					echo '<tr><td>' . esc_html( $r['code'] . ' ' . $r['name'] ) . '</td><td>' . esc_html( self::money( $r['amount'] ) ) . '</td></tr>';
				}
				echo '<tr><th>جمع دارایی‌ها</th><th>' . esc_html( self::money( $bs['totals']['asset'] ) ) . '</th></tr></table></div><div><h3>بدهی‌ها و حقوق مالکانه</h3><table class="widefat">';
				foreach ( array_merge( $bs['liability'], $bs['equity'] ) as $r ) {
					echo '<tr><td>' . esc_html( $r['code'] . ' ' . $r['name'] ) . '</td><td>' . esc_html( self::money( $r['amount'] ) ) . '</td></tr>';
				}
				echo '<tr><td>سود (زیان) دوره جاری</td><td>' . esc_html( self::money( $bs['totals']['profit'] ) ) . '</td></tr>';
				echo '<tr><th>جمع بدهی و حقوق مالکانه</th><th>' . esc_html( self::money( $bs['totals']['liability'] + $bs['totals']['equity_with_profit'] ) ) . '</th></tr></table></div></div>';
				echo $bs['balanced'] ? '<p class="mda-ok">ترازنامه تراز است.</p>' : '<p class="mda-bad">ترازنامه تراز نیست؛ با پشتیبانی تماس بگیرید.</p>';
				break;
			case 'ar':
			case 'ap':
				$aging = MDA_Ledger_Report_Service::aging( 'ar' === $tab ? 'customer' : 'supplier', $to );
				$export( $tab );
				echo '<table class="widefat striped"><thead><tr><th>طرف‌حساب</th><th>تلفن</th><th>۰ تا ۳۰ روز</th><th>۳۱ تا ۶۰</th><th>۶۱ تا ۹۰</th><th>بیش از ۹۰</th><th>جمع</th><th>بستانکاری/پیش‌پرداخت</th>' . ( 'ar' === $tab && MDA_Integrations::sms_available() ? '<th>یادآوری</th>' : '' ) . '</tr></thead><tbody>';
				foreach ( $aging['rows'] as $r ) {
					echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td dir="ltr">' . esc_html( (string) $r['phone'] ) . '</td><td>' . esc_html( self::money( $r['b0'] ) ) . '</td><td>' . esc_html( self::money( $r['b30'] ) ) . '</td><td>' . esc_html( self::money( $r['b60'] ) ) . '</td><td>' . esc_html( self::money( $r['b90'] ) ) . '</td><td><strong>' . esc_html( self::money( $r['total'] ) ) . '</strong></td><td>' . esc_html( self::money( $r['credit'] ) ) . '</td>';
					if ( 'ar' === $tab && MDA_Integrations::sms_available() ) {
						echo '<td>' . ( $r['total'] > 0 && $r['phone'] ? '<a class="button button-small" href="' . esc_url( MDA_Integrations::reminder_url( (int) $r['party_id'] ) ) . '" onclick="return confirm(\'پیامک یادآوری بدهی ارسال شود؟\')"><span class="dashicons dashicons-email-alt"></span> پیامک</a>' : '' ) . '</td>';
					}
					echo '</tr>';
				}
				echo '</tbody><tfoot><tr><th colspan="2">جمع</th><th>' . esc_html( self::money( $aging['totals']['b0'] ) ) . '</th><th>' . esc_html( self::money( $aging['totals']['b30'] ) ) . '</th><th>' . esc_html( self::money( $aging['totals']['b60'] ) ) . '</th><th>' . esc_html( self::money( $aging['totals']['b90'] ) ) . '</th><th>' . esc_html( self::money( $aging['totals']['total'] ) ) . '</th><th></th></tr></tfoot></table>';
				break;
			default:
				self::range_form( 'mda-ledgers', array( 'tab' => 'trial' ) );
				$tb = MDA_Ledger_Report_Service::trial_balance( $from, $to );
				$export( 'trial' );
				echo '<table class="widefat striped"><thead><tr><th>کد</th><th>حساب</th><th>مانده اول</th><th>گردش بدهکار</th><th>گردش بستانکار</th><th>مانده پایان</th></tr></thead><tbody>';
				foreach ( $tb['rows'] as $r ) {
					$url = add_query_arg( array( 'page' => 'mda-ledgers', 'tab' => 'ledger', 'account_id' => (int) $r['id'], 'from' => self::get( 'from' ), 'to' => self::get( 'to' ) ), admin_url( 'admin.php' ) );
					echo '<tr><td>' . esc_html( $r['account_code'] ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html( $r['account_name'] ) . '</a></td><td>' . esc_html( self::money( $r['opening'] ) ) . '</td><td>' . esc_html( self::money( $r['debit'] ) ) . '</td><td>' . esc_html( self::money( $r['credit'] ) ) . '</td><td>' . esc_html( self::money( $r['closing'] ) ) . '</td></tr>';
				}
				echo '</tbody><tfoot><tr><th colspan="2">جمع</th><th>' . esc_html( self::money( $tb['totals']['opening'] ) ) . '</th><th>' . esc_html( self::money( $tb['totals']['debit'] ) ) . '</th><th>' . esc_html( self::money( $tb['totals']['credit'] ) ) . '</th><th>' . esc_html( self::money( $tb['totals']['closing'] ) ) . '</th></tr></tfoot></table>';
				echo $tb['balanced'] ? '<p class="mda-ok">تراز آزمایشی تراز است.</p>' : '<p class="mda-bad">تراز آزمایشی تراز نیست.</p>';
				echo '<p class="description">مانده مثبت = بدهکار، مانده داخل پرانتز = بستانکار.</p>';
		}
		echo '</div></div>';
	}

	public static function handle_export_csv(): void {
		self::guard();
		check_admin_referer( 'mda_export_csv' );
		list( $from, $to ) = self::range_from_request();
		$report = self::get( 'report' );
		switch ( $report ) {
			case 'ledger':
				$l    = MDA_Ledger_Report_Service::account_ledger( absint( self::get( 'account_id' ) ), $from, $to, absint( self::get( 'party_id' ) ) );
				$rows = array( array( '', '', 'مانده از قبل', '', '', '', $l['opening'] ) );
				foreach ( $l['rows'] as $r ) {
					$rows[] = array( MDA_Jalali::format( $r['document_date'], false ), $r['document_number'], $r['memo'] ?: $r['description'], $r['party_name'], $r['debit_minor'], $r['credit_minor'], $r['balance'] );
				}
				MDA_Ledger_Report_Service::stream_csv( 'ledger.csv', array( 'تاریخ', 'سند', 'شرح', 'طرف‌حساب', 'بدهکار', 'بستانکار', 'مانده' ), $rows );
				break;
			case 'ar':
			case 'ap':
				$aging = MDA_Ledger_Report_Service::aging( 'ar' === $report ? 'customer' : 'supplier', $to );
				$rows  = array_map( static fn( $r ) => array( $r['name'], $r['phone'], $r['b0'], $r['b30'], $r['b60'], $r['b90'], $r['total'], $r['credit'] ), $aging['rows'] );
				MDA_Ledger_Report_Service::stream_csv( $report . '-aging.csv', array( 'طرف‌حساب', 'تلفن', '0-30', '31-60', '61-90', '90+', 'جمع', 'بستانکاری' ), $rows );
				break;
			default:
				$tb   = MDA_Ledger_Report_Service::trial_balance( $from, $to );
				$rows = array_map( static fn( $r ) => array( $r['account_code'], $r['account_name'], $r['opening'], $r['debit'], $r['credit'], $r['closing'] ), $tb['rows'] );
				MDA_Ledger_Report_Service::stream_csv( 'trial-balance.csv', array( 'کد', 'حساب', 'مانده اول', 'بدهکار', 'بستانکار', 'مانده پایان' ), $rows );
		}
	}

	/* ---------------------------------------------------------------- inventory */

	public static function render_inventory(): void {
		self::guard();
		if ( ! self::db_ready() ) {
			return;
		}
		$warehouses = array_values( array_filter( MDA_Inventory_Service::warehouses(), static fn( $w ) => 1 === (int) $w['is_active'] ) );
		echo '<div class="wrap" dir="rtl"><h1>انبار و سفارش‌های معوق</h1>';
		self::notices();

		$open = MDA_Supply_Service::open_backorders( 300 );
		echo '<div class="mda-card"><h2>سفارش‌های معوق (فروخته‌شده، هنوز خریداری نشده)</h2>';
		if ( ! $open ) {
			echo '<p class="mda-muted">سفارش معوقی وجود ندارد.</p>';
		} else {
			echo '<p class="description">با ثبت فاکتور خرید این کالاها، تحویل و بهای تمام‌شده به‌صورت خودکار و به ترتیب قدیمی‌ترین سفارش ثبت می‌شود.</p><table class="widefat striped"><thead><tr><th>سفارش</th><th>کالا</th><th>SKU</th><th>تعداد معوق</th><th>تاریخ فروش</th></tr></thead><tbody>';
			foreach ( $open as $b ) {
				echo '<tr><td>#' . (int) $b['order_id'] . '</td><td>' . esc_html( $b['product_name_snapshot'] ) . '</td><td>' . esc_html( (string) $b['sku_snapshot'] ) . '</td><td>' . esc_html( rtrim( rtrim( number_format( (int) $b['open_scaled'] / (int) $b['quantity_scale'], 6, '.', '' ), '0' ), '.' ) ) . '</td><td>' . esc_html( self::jdate( substr( (string) $b['created_at'], 0, 10 ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="mda-card"><h2>موجودی افتتاحیه / شمارش انبار</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_inventory_adjust' );
		echo '<input type="hidden" name="action" value="mda_inventory_adjust"><input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		echo '<p><label>نوع <select name="adjustment_type"><option value="opening">موجودی افتتاحیه (کالای بدون گردش)</option><option value="count">شمارش انبار (تعدیل کسری/اضافی)</option></select></label> ';
		echo '<label>انبار <select name="warehouse_id">';
		foreach ( $warehouses as $w ) {
			echo '<option value="' . (int) $w['id'] . '">' . esc_html( $w['warehouse_name'] ) . '</option>';
		}
		echo '</select></label></p><p><label>شناسه محصول ووکامرس <input name="product_id" type="number" min="1" required></label> <label>شناسه تنوع (اختیاری) <input name="variation_id" type="number" min="0" value="0"></label> ';
		echo '<label>تعداد شمارش‌شده <input name="counted" required inputmode="decimal" size="8"></label> <label>بهای واحد (' . esc_html( MDA_Settings::currency_label() ) . ') <input class="mda-amount" name="unit_cost" inputmode="numeric" size="12"></label></p>';
		echo '<p><label>تاریخ <input class="mda-jdate" name="date" value="' . esc_attr( self::today_input() ) . '" size="11"></label> <label>توضیح <input name="reason" class="regular-text"></label></p>';
		echo '<p class="description">در شمارش انبار، بهای واحد فقط برای کالایی لازم است که هنوز بهای تمام‌شده ندارد؛ در غیر این صورت میانگین موزون فعلی استفاده می‌شود.</p><p><button class="button button-primary">ثبت</button></p></form></div>';

		echo '<div class="mda-card"><h2>آخرین تعدیلات</h2><table class="widefat striped"><thead><tr><th>تاریخ</th><th>نوع</th><th>کالا</th><th>قبل</th><th>بعد</th><th>ارزش</th><th>توضیح</th></tr></thead><tbody>';
		foreach ( MDA_Inventory_Adjustment_Service::recent( 50 ) as $a ) {
			$fmt = static fn( $q ) => rtrim( rtrim( number_format( (int) $q / (int) $a['quantity_scale'], 6, '.', '' ), '0' ), '.' );
			echo '<tr><td>' . esc_html( self::jdate( substr( (string) $a['created_at'], 0, 10 ) ) ) . '</td><td>' . esc_html( 'opening' === $a['adjustment_type'] ? 'افتتاحیه' : 'شمارش' ) . '</td><td>' . (int) $a['product_id'] . ( (int) $a['variation_id'] ? ' / ' . (int) $a['variation_id'] : '' ) . '</td><td>' . esc_html( $fmt( $a['quantity_before_scaled'] ) ) . '</td><td>' . esc_html( $fmt( $a['quantity_after_scaled'] ) ) . '</td><td>' . esc_html( self::money( (int) $a['value_minor'] ) ) . '</td><td>' . esc_html( (string) $a['reason'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	public static function handle_inventory_adjust(): void {
		self::guard();
		check_admin_referer( 'mda_inventory_adjust' );
		try {
			$cost_raw = self::post( 'unit_cost' );
			$result   = MDA_Inventory_Adjustment_Service::adjust(
				'count' === self::post( 'adjustment_type' ) ? 'count' : 'opening',
				absint( self::post( 'product_id' ) ),
				absint( self::post( 'variation_id' ) ),
				absint( self::post( 'warehouse_id' ) ),
				MDA_Jalali::latin_digits( self::post( 'counted' ) ),
				'' === $cost_raw ? null : self::amount( $cost_raw ),
				self::date_input( self::post( 'date' ), 'تعدیل' ),
				self::post( 'reason' ),
				'adj-' . self::post( 'request_key' )
			);
			self::redirect( 'mda-inventory', 'تعدیل انبار ثبت شد' . ( $result['value_minor'] ? ' (ارزش ' . number_format( (int) $result['value_minor'] ) . ')' : '' ) . '.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-inventory', $e->getMessage(), true );
		}
	}

	/* ---------------------------------------------------------------- settings */

	public static function render_settings(): void {
		self::guard();
		if ( ! self::db_ready() ) {
			return;
		}
		$s = MDA_Settings::all();
		echo '<div class="wrap" dir="rtl"><h1>تنظیمات، دوره مالی و پشتیبان</h1>';
		self::notices();

		echo '<div class="mda-card"><h2>دوره‌های مالی</h2><table class="widefat"><thead><tr><th>نام</th><th>از</th><th>تا</th><th>وضعیت</th><th></th></tr></thead><tbody>';
		foreach ( MDA_Journal_Service::periods() as $p ) {
			echo '<tr><td>' . esc_html( $p['period_name'] ) . '</td><td>' . esc_html( self::jdate( $p['starts_on'] ) ) . '</td><td>' . esc_html( self::jdate( $p['ends_on'] ) ) . '</td><td>' . esc_html( 'closed' === $p['status'] ? 'بسته' : 'باز' ) . '</td><td>';
			$action = 'closed' === $p['status'] ? 'mda_reopen_period' : 'mda_close_period';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
			wp_nonce_field( $action . '_' . (int) $p['id'] );
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="period_id" value="' . (int) $p['id'] . '">';
			if ( 'closed' === $p['status'] ) {
				echo '<button class="button" onclick="return confirm(\'باز کردن دوره بسته، ثبت سند در آن را دوباره ممکن می‌کند. ادامه؟\')">باز کردن</button>';
			} else {
				echo '<label><input type="checkbox" name="closing_entry" value="1" checked> سند اختتامیه (انتقال سود و زیان به ۳۲۰۰)</label> <button class="button button-primary" onclick="return confirm(\'پس از بستن، ثبت سند در این دوره ممکن نیست. ادامه؟\')">بستن دوره</button>';
			}
			echo '</form></td></tr>';
		}
		echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
		wp_nonce_field( 'mda_create_period' );
		list( $jy ) = MDA_Jalali::to_jalali( (int) current_time( 'Y' ), (int) current_time( 'n' ), (int) current_time( 'j' ) );
		echo '<input type="hidden" name="action" value="mda_create_period"><label>سال مالی <input name="jalali_year" type="number" min="1390" max="1500" value="' . (int) $jy . '"></label> <button class="button">ایجاد سال مالی</button></form></div>';

		echo '<div class="mda-card"><h2>تنظیمات عمومی و اطلاعات فروشنده</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_save_settings' );
		echo '<input type="hidden" name="action" value="mda_save_settings"><table class="form-table">';
		echo '<tr><th>نمایش تاریخ</th><td><label><input type="checkbox" name="jalali_dates" value="1"' . checked( $s['jalali_dates'], 1, false ) . '> تاریخ‌ها شمسی نمایش و وارد شوند</label></td></tr>';
		echo '<tr><th>واحد پول</th><td>' . esc_html( MDA_Settings::currency_label() ) . ' <span class="description">(از تنظیمات ووکامرس)</span></td></tr>';
		echo '<tr><th>پشتیبان خودکار</th><td><label><input type="checkbox" name="backup_enabled" value="1"' . checked( $s['backup_enabled'], 1, false ) . '> هر شب</label> — نگه‌داری <input type="number" name="backup_keep" min="3" max="90" value="' . (int) $s['backup_keep'] . '" style="width:70px"> نسخه آخر</td></tr>';
		echo '<tr><th>زمان تأمین پیش‌فرض کالای سفارشی</th><td><input type="number" name="default_lead_time" min="0" max="365" value="' . (int) $s['default_lead_time'] . '" style="width:70px"> روز کاری</td></tr>';
		echo '<tr><th>یادآوری پیامکی سررسید چک</th><td>' . ( MDA_Integrations::sms_available() ? '' : '<span class="mda-muted">(نیازمند افزونه «پیامک ورود، رهگیری پست، ووکامرس») </span>' ) . 'چک‌هایی که تا <input type="number" name="cheque_reminder_days" min="0" max="30" value="' . (int) $s['cheque_reminder_days'] . '" style="width:70px"> روز آینده سررسید دارند، هر صبح برای شماره‌های مدیر ارسال شود (۰ = خاموش).</td></tr>';
		echo '<tr><th>نام فروشنده</th><td><input name="seller_name" class="regular-text" value="' . esc_attr( $s['seller_name'] ) . '"></td></tr>';
		echo '<tr><th>شناسه ملی / کد ملی</th><td><input name="seller_national_id" dir="ltr" value="' . esc_attr( $s['seller_national_id'] ) . '"></td></tr>';
		echo '<tr><th>کد اقتصادی</th><td><input name="seller_economic_code" dir="ltr" value="' . esc_attr( $s['seller_economic_code'] ) . '"></td></tr>';
		echo '<tr><th>کد پستی</th><td><input name="seller_postal_code" dir="ltr" value="' . esc_attr( $s['seller_postal_code'] ) . '"></td></tr>';
		echo '<tr><th>سامانه مؤدیان</th><td><a href="' . esc_url( admin_url( 'admin.php?page=mda-identity&tab=moodian' ) ) . '">تنظیمات سامانه مؤدیان و شاهکار</a></td></tr>';
		echo '</table><p><button class="button button-primary">ذخیره تنظیمات</button></p></form></div>';

		echo '<div id="mda-price-messages"></div>';
		MDA_Price_Message::render_settings_card();

		echo '<div class="mda-card"><h2>پشتیبان‌گیری</h2>';
		try {
			$backups = MDA_Backup_Service::list();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
			wp_nonce_field( 'mda_backup_create' );
			echo '<input type="hidden" name="action" value="mda_backup_create"><button class="button button-primary">تهیه پشتیبان همین حالا</button></form>';
			echo '<table class="widefat striped"><thead><tr><th>فایل</th><th>حجم</th><th>زمان</th><th></th></tr></thead><tbody>';
			foreach ( $backups as $b ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'mda_backup_download', 'file' => $b['file_name'] ), admin_url( 'admin-post.php' ) ), 'mda_backup_download' );
				echo '<tr><td dir="ltr">' . esc_html( $b['file_name'] ) . '</td><td>' . esc_html( size_format( $b['size_bytes'] ) ) . '</td><td>' . esc_html( self::jdate( $b['modified'] ) . ' ' . substr( $b['modified'], 11, 5 ) ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">دانلود</a></td></tr>';
			}
			echo '</tbody></table><p class="description">برای بازگردانی، فایل پشتیبان را جایگزین فایل دیتابیس در پوشه خصوصی کنید (پیش از آن یک پشتیبان تازه بگیرید).</p>';
		} catch ( Throwable $e ) {
			echo '<p class="mda-bad">' . esc_html( $e->getMessage() ) . '</p>';
		}
		echo '</div>';

		echo '<div class="mda-card"><h2>دیتابیس</h2><p>مسیر فایل: <code dir="ltr">' . esc_html( MDA_Database::path() ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
		wp_nonce_field( 'mda_reclaim_database' );
		echo '<input type="hidden" name="action" value="mda_reclaim_database"><button class="button" onclick="return confirm(\'فقط وقتی سایت را به مسیر یا هاست جدید منتقل کرده‌اید و فایل دیتابیس را خودتان کپی کرده‌اید تأیید کنید.\')">تأیید انتقال دیتابیس به این نصب</button></form>';
		$review = MDA_Order_Adjustment_Service::needing_review( 50 );
		if ( $review ) {
			echo '<h3>استردادهای نیازمند بررسی</h3><table class="widefat"><tbody>';
			foreach ( $review as $r ) {
				echo '<tr><td>سفارش #' . (int) $r['order_id'] . '</td><td>' . esc_html( (string) $r['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}

	public static function handle_save_settings(): void {
		self::guard();
		check_admin_referer( 'mda_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in MDA_Settings::save().
		MDA_Settings::save( wp_unslash( $_POST ) );
		self::redirect( 'mda-settings', 'تنظیمات ذخیره شد.' );
	}

	public static function handle_create_period(): void {
		self::guard();
		check_admin_referer( 'mda_create_period' );
		try {
			$jy = absint( self::post( 'jalali_year' ) );
			list( $s, $e ) = MDA_Jalali::year_range( $jy );
			MDA_Journal_Service::create_period( 'سال مالی ' . MDA_Jalali::persian_digits( (string) $jy ), (string) $s, (string) $e );
			self::redirect( 'mda-settings', 'سال مالی ایجاد شد.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-settings', $e->getMessage(), true );
		}
	}

	public static function handle_close_period(): void {
		self::guard();
		$id = absint( self::post( 'period_id' ) );
		check_admin_referer( 'mda_close_period_' . $id );
		try {
			MDA_Journal_Service::close_period( $id, '1' === self::post( 'closing_entry' ) );
			self::redirect( 'mda-settings', 'دوره مالی بسته شد.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-settings', $e->getMessage(), true );
		}
	}

	public static function handle_reopen_period(): void {
		self::guard();
		$id = absint( self::post( 'period_id' ) );
		check_admin_referer( 'mda_reopen_period_' . $id );
		try {
			MDA_Journal_Service::reopen_period( $id );
			self::redirect( 'mda-settings', 'دوره مالی باز شد.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-settings', $e->getMessage(), true );
		}
	}

	public static function handle_backup_create(): void {
		self::guard();
		check_admin_referer( 'mda_backup_create' );
		try {
			$b = MDA_Backup_Service::create( 'manual' );
			self::redirect( 'mda-settings', 'پشتیبان ' . $b['file_name'] . ' ساخته شد.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-settings', $e->getMessage(), true );
		}
	}

	public static function handle_backup_download(): void {
		self::guard();
		check_admin_referer( 'mda_backup_download' );
		MDA_Backup_Service::download( self::get( 'file' ) );
	}

	public static function handle_reclaim(): void {
		self::guard();
		check_admin_referer( 'mda_reclaim_database' );
		try {
			MDA_Database::reclaim();
			self::redirect( 'mda-settings', 'دیتابیس به این نصب متصل شد.' );
		} catch ( Throwable $e ) {
			self::redirect( 'mda-settings', $e->getMessage(), true );
		}
	}
}
