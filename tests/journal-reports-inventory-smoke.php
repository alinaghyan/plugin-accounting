<?php
/**
 * Manual/opening journals, reversal, fiscal period close, trial balance, balance sheet, aging,
 * opening stock and stock count adjustments, and SQLite VACUUM INTO backups.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
define( 'DAY_IN_SECONDS', 86400 );
foreach ( array( 'ledger', 'supply', 'journal', 'ledger-report', 'inventory-adjustment', 'sales' ) as $name ) {
	require_once dirname( __DIR__ ) . '/includes/class-mda-' . $name . '-service.php';
}
require_once dirname( __DIR__ ) . '/includes/class-mda-jalali.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-settings.php';

$db   = mda_test_database();
$cash = mda_account_id( $db, '1110' );
$cap  = mda_account_id( $db, '3900' );
$rent = mda_account_id( $db, '5210' );
$sale = mda_account_id( $db, '4100' );
$ar   = mda_account_id( $db, '1200' );

// Opening balance: 1,000,000 cash.
$open = MDA_Journal_Service::post_manual(
	'2025-03-21',
	'مانده اول دوره',
	array(
		array( 'account_id' => $cash, 'debit_minor' => 1000000, 'credit_minor' => 0 ),
		array( 'account_id' => $cap, 'debit_minor' => 0, 'credit_minor' => 1000000 ),
	),
	'opening-test-0001',
	'opening'
);
mda_assert( $open['document_id'] > 0, 'opening journal posted' );

// Group accounts are rejected for manual entry.
$group = mda_account_id( $db, '1100' );
mda_assert_throws(
	static fn() => MDA_Journal_Service::post_manual( '2025-04-01', 'x', array( array( 'account_id' => $group, 'debit_minor' => 5, 'credit_minor' => 0 ), array( 'account_id' => $cap, 'debit_minor' => 0, 'credit_minor' => 5 ) ), 'group-test-0001' ),
	'group account must be refused',
	'گروه'
);

// Rent expense, then reversal.
$rentDoc = MDA_Journal_Service::post_manual( '2025-05-01', 'اجاره', array( array( 'account_id' => $rent, 'debit_minor' => 200000, 'credit_minor' => 0 ), array( 'account_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 200000 ) ), 'rent-test-00001' );
$rev     = MDA_Journal_Service::reverse( (int) $rentDoc['document_id'], '2025-05-02', 'ثبت اشتباه' );
mda_assert( 'voided' === $db->query( 'SELECT document_status FROM mda_documents WHERE id=' . (int) $rentDoc['document_id'] )->fetchColumn(), 'original voided' );
mda_assert_throws( static fn() => MDA_Journal_Service::reverse( (int) $rentDoc['document_id'], '2025-05-03', 'دوباره' ), 'double reversal refused' );
MDA_Journal_Service::post_manual( '2025-05-03', 'اجاره صحیح', array( array( 'account_id' => $rent, 'debit_minor' => 150000, 'credit_minor' => 0 ), array( 'account_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 150000 ) ), 'rent-test-00002' );

// Customer credit sale and partial collection for aging.
$db->exec( "INSERT INTO mda_parties (party_code,display_name,phone,is_customer,created_at,updated_at) VALUES ('C-1','Ali','0912',1,'2025-01-01','2025-01-01')" );
$cust = (int) $db->lastInsertId();
$db->exec( "INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES ($cust,'customer','2025-01-01')" );
MDA_Ledger_Service::post_document( array( 'document_type' => 'manual', 'idempotency_key' => 'credit-sale-0001', 'document_date' => '2025-06-01' ), array( array( 'account_id' => $ar, 'party_id' => $cust, 'debit_minor' => 300000, 'credit_minor' => 0 ), array( 'account_id' => $sale, 'debit_minor' => 0, 'credit_minor' => 300000 ) ) );
MDA_Ledger_Service::post_document( array( 'document_type' => 'manual', 'idempotency_key' => 'credit-sale-0002', 'document_date' => '2025-08-15' ), array( array( 'account_id' => $ar, 'party_id' => $cust, 'debit_minor' => 100000, 'credit_minor' => 0 ), array( 'account_id' => $sale, 'debit_minor' => 0, 'credit_minor' => 100000 ) ) );
MDA_Ledger_Service::post_document( array( 'document_type' => 'manual', 'idempotency_key' => 'collect-00001', 'document_date' => '2025-08-20' ), array( array( 'account_id' => $cash, 'debit_minor' => 250000, 'credit_minor' => 0 ), array( 'account_id' => $ar, 'party_id' => $cust, 'debit_minor' => 0, 'credit_minor' => 250000 ) ) );

$aging = MDA_Ledger_Report_Service::aging( 'customer', '2025-09-01' );
mda_assert( 1 === count( $aging['rows'] ), 'one customer in aging' );
$row = $aging['rows'][0];
// FIFO: 250k collected settles the June sale first → 50k of June (92 days old, 90+) + 100k of August (17 days).
mda_assert( 50000 === $row['b90'] && 100000 === $row['b0'] && 150000 === $row['total'], 'FIFO aging buckets' );

// Trial balance & balance sheet.
$tb = MDA_Ledger_Report_Service::trial_balance( '2025-03-21', '2026-03-20' );
mda_assert( $tb['balanced'], 'trial balance balanced' );
$bs = MDA_Ledger_Report_Service::balance_sheet( '2026-03-20' );
mda_assert( $bs['balanced'], 'balance sheet balanced' );
mda_assert( 250000 === $bs['totals']['profit'], 'profit 400k sales − 150k rent' );

// Ledger with running balance.
$ledger = MDA_Ledger_Report_Service::account_ledger( $cash, '2025-03-21', '2026-03-20' );
mda_assert( 1000000 - 200000 + 200000 - 150000 + 250000 === $ledger['closing'], 'cash ledger running balance' );

// Close the fiscal year: P&L goes to retained earnings, period locked.
$period = MDA_Journal_Service::create_period( 'سال مالی ۱۴۰۴', '2025-03-21', '2026-03-20' );
mda_assert_throws( static fn() => MDA_Journal_Service::create_period( 'overlap', '2026-01-01', '2026-06-01' ), 'overlapping period refused', 'هم‌پوشانی' );
$closed = MDA_Journal_Service::close_period( $period );
mda_assert( null !== $closed['closing_document_id'], 'closing document posted' );
$bs2 = MDA_Ledger_Report_Service::balance_sheet( '2026-03-20' );
mda_assert( 0 === $bs2['totals']['profit'] && $bs2['balanced'], 'temporary accounts zeroed and balance sheet balanced' );
$retained = (int) $db->query( "SELECT SUM(e.credit_minor)-SUM(e.debit_minor) FROM mda_journal_entries e JOIN mda_accounts a ON a.id=e.account_id WHERE a.account_code='3200'" )->fetchColumn();
mda_assert( 250000 === $retained, 'profit moved to retained earnings' );
mda_assert_throws( static fn() => MDA_Journal_Service::post_manual( '2025-12-01', 'late', array( array( 'account_id' => $rent, 'debit_minor' => 1, 'credit_minor' => 0 ), array( 'account_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 1 ) ), 'late-doc-00001' ), 'closed period locks posting' );
MDA_Journal_Service::reopen_period( $period );
MDA_Journal_Service::post_manual( '2025-12-01', 'late', array( array( 'account_id' => $rent, 'debit_minor' => 1000, 'credit_minor' => 0 ), array( 'account_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 1000 ) ), 'late-doc-00002' );
$again = MDA_Journal_Service::close_period( $period );
mda_assert( null !== $again['closing_document_id'], 're-closing posts only the delta' );
mda_assert( 0 === MDA_Ledger_Report_Service::balance_sheet( '2026-03-20' )['totals']['profit'], 'delta closed' );

// Opening stock, count shortage, count surplus fulfilling a backorder.
$w = (int) $db->query( "SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'" )->fetchColumn();
$r = MDA_Inventory_Adjustment_Service::adjust( 'opening', 800, 0, $w, '10', 5000, '2026-03-21', 'افتتاحیه', 'opening-800-0001' );
mda_assert( 50000 === $r['value_minor'], 'opening stock value 10 x 5000' );
mda_assert_throws( static fn() => MDA_Inventory_Adjustment_Service::adjust( 'opening', 800, 0, $w, '3', 5000, '2026-03-21', '', 'opening-800-0002' ), 'second opening refused' );
$r = MDA_Inventory_Adjustment_Service::adjust( 'count', 800, 0, $w, '8', null, '2026-04-01', 'کسری', 'count-800-00001' );
mda_assert( -2000000 === $r['difference_scaled'] && 10000 === $r['value_minor'], 'shortage of 2 at average cost' );
$dup = MDA_Inventory_Adjustment_Service::adjust( 'count', 800, 0, $w, '8', null, '2026-04-01', 'کسری', 'count-800-00001' );
mda_assert( ! empty( $dup['duplicate'] ), 'adjustment idempotent' );
$inv = (int) $db->query( "SELECT SUM(e.debit_minor)-SUM(e.credit_minor) FROM mda_journal_entries e JOIN mda_accounts a ON a.id=e.account_id WHERE a.account_code='1300'" )->fetchColumn();
mda_assert( 40000 === $inv, 'inventory account equals 8 x 5000' );

MDA_Supply_Service::save_settings( 801, 0, MDA_Supply_Service::MODE_ON_DEMAND, null, null );
MDA_Sales_Service::post_order_payload( array( 'order_id' => 5001, 'currency' => 'IRR', 'date' => '2026-04-02', 'warehouse_id' => $w, 'channel' => 'phone', 'idempotency_key' => 'sale-5001-test', 'customer' => array( 'name' => 'X', 'phone' => '0935' ), 'items' => array( array( 'order_item_id' => 1, 'product_id' => 801, 'variation_id' => 0, 'name' => 'OD', 'quantity_scaled' => 1000000, 'quantity_scale' => 1000000, 'revenue_minor' => 9000, 'tax_minor' => 0 ) ), 'shipping_minor' => 0, 'fees_minor' => 0, 'tax_minor' => 0, 'total_minor' => 9000 ) );
MDA_Inventory_Adjustment_Service::adjust( 'count', 801, 0, $w, '1', 7000, '2026-04-03', 'دریافت از تأمین‌کننده بدون فاکتور', 'count-801-00001' );
mda_assert( 0 === MDA_Supply_Service::open_quantity( $db, 801, 0, $w ), 'stock surplus fulfils open backorder' );
mda_assert( MDA_Ledger_Report_Service::trial_balance( '2020-01-01', '2030-01-01' )['balanced'], 'books balanced after all operations' );

// VACUUM INTO with a bound file name produces a valid copy.
$file = sys_get_temp_dir() . '/mda-backup-test-' . getmypid() . '.sqlite';
@unlink( $file );
$db->prepare( 'VACUUM INTO :f' )->execute( array( ':f' => $file ) );
$copy = new PDO( 'sqlite:' . $file );
mda_assert( 'ok' === $copy->query( 'PRAGMA integrity_check' )->fetchColumn(), 'backup copy passes integrity check' );
mda_assert( (int) $copy->query( 'SELECT COUNT(*) FROM mda_documents' )->fetchColumn() === (int) $db->query( 'SELECT COUNT(*) FROM mda_documents' )->fetchColumn(), 'backup contains all documents' );
$copy = null;
@unlink( $file );

// Jalali.
mda_assert( '2026-11-16' === MDA_Jalali::parse( '۱۴۰۵/۰۸/۲۵' ), 'Jalali parse with Persian digits' );
mda_assert( null === MDA_Jalali::parse( '1404/12/30' ), '1404 is not leap' );

echo "OK: journals, reversal, fiscal close/reopen, trial balance, balance sheet, FIFO aging, opening stock, stock count, backorder fulfilment by count, backup and Jalali passed.\n";
