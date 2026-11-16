<?php
/**
 * Ledger integrity (balance guard, immutable GL, closed periods, site fingerprint)
 * and on-demand supply (sale without stock -> backorder -> purchase fulfils COGS).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-ledger-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-supply-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-purchase-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-sales-service.php';

$db  = mda_test_database();
$now = '2026-11-16 10:00:00';
$cash = mda_account_id( $db, '1110' );
$sales = mda_account_id( $db, '4100' );

// 1. Central balance guard: an unbalanced document written by any service is rolled back.
mda_assert_throws(
	static function () use ( $cash, $sales, $now ): void {
		MDA_Database::transaction(
			static function ( PDO $db ) use ( $cash, $sales, $now ): void {
				$db->exec( "INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,currency_code,document_date,created_at,posted_at) VALUES ('BAD-1','manual','posted','bad-doc-0001','IRR','2026-11-16','$now','$now')" );
				$id = (int) $db->lastInsertId();
				$db->exec( "INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) VALUES ($id,1,$cash,100,0,'$now'),($id,2,$sales,0,90,'$now')" );
			}
		);
	},
	'unbalanced document must be rejected',
	'تراز نیست'
);
mda_assert( 0 === (int) $db->query( "SELECT COUNT(*) FROM mda_documents WHERE document_number='BAD-1'" )->fetchColumn(), 'unbalanced document was rolled back' );

// 2. Balanced manual document through the ledger service, then immutability.
$doc = MDA_Ledger_Service::post_document(
	array( 'document_type' => 'manual', 'idempotency_key' => 'manual-doc-0001', 'document_date' => '2026-11-16', 'description' => 'test' ),
	array(
		array( 'account_id' => $cash, 'debit_minor' => 500, 'credit_minor' => 0 ),
		array( 'account_id' => $sales, 'debit_minor' => 0, 'credit_minor' => 500 ),
	)
);
$id = (int) $doc['document_id'];
mda_assert_throws( static fn() => $db->exec( "UPDATE mda_journal_entries SET debit_minor=1 WHERE document_id=$id" ), 'journal lines are append-only', 'append-only' );
mda_assert_throws( static fn() => $db->exec( "DELETE FROM mda_journal_entries WHERE document_id=$id" ), 'journal lines cannot be deleted', 'append-only' );
mda_assert_throws( static fn() => $db->exec( "DELETE FROM mda_documents WHERE id=$id" ), 'documents cannot be deleted', 'append-only' );
mda_assert_throws( static fn() => $db->exec( "UPDATE mda_documents SET document_date='2026-01-01' WHERE id=$id" ), 'posted document date is immutable', 'immutable' );
$db->exec( "UPDATE mda_documents SET document_status='voided', voided_at='$now' WHERE id=$id" );
mda_assert_throws( static fn() => $db->exec( "UPDATE mda_documents SET document_status='posted' WHERE id=$id" ), 'voided document cannot be revived', 'immutable' );

// 3. Closed fiscal period is enforced by the database itself.
$db->exec( "INSERT INTO mda_financial_periods (period_name,starts_on,ends_on,status,closed_at) VALUES ('1404','2025-03-21','2026-03-20','closed','$now')" );
mda_assert_throws(
	static fn() => MDA_Ledger_Service::post_document(
		array( 'document_type' => 'manual', 'idempotency_key' => 'closed-doc-0001', 'document_date' => '2026-01-10' ),
		array(
			array( 'account_id' => $cash, 'debit_minor' => 1, 'credit_minor' => 0 ),
			array( 'account_id' => $sales, 'debit_minor' => 0, 'credit_minor' => 1 ),
		)
	),
	'closed period must reject documents'
);

// 4. Site fingerprint: a database claimed by another installation is refused.
MDA_Database::claim( $db );
$mine = (string) $db->query( "SELECT setting_value FROM mda_settings WHERE setting_key='site_fingerprint'" )->fetchColumn();
mda_assert( strlen( $mine ) === 24, 'fingerprint stored on first claim' );
$db->exec( "UPDATE mda_settings SET setting_value='someone-else-0000000000' WHERE setting_key='site_fingerprint'" );
mda_assert_throws( static fn() => MDA_Database::claim( $db ), 'foreign database must be refused', 'نصب دیگری' );
$db->exec( "UPDATE mda_settings SET setting_value='$mine' WHERE setting_key='site_fingerprint'" );

// 5. On-demand supply.
$db->exec( "INSERT INTO mda_parties (party_code,display_name,is_supplier,created_at,updated_at) VALUES ('SUP-9','Supplier',1,'$now','$now')" );
$supplier = (int) $db->lastInsertId();
$db->exec( "INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES ($supplier,'supplier','$now')" );
$warehouse = (int) $db->query( "SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'" )->fetchColumn();

$sale_payload = static function ( int $order, int $qty_units ) use ( $warehouse ): array {
	return array(
		'order_id'        => $order,
		'currency'        => 'IRR',
		'date'            => '2026-11-16',
		'warehouse_id'    => $warehouse,
		'channel'         => 'phone',
		'idempotency_key' => 'sale-test-order-' . $order,
		'customer'        => array( 'name' => 'C', 'phone' => '0912000' . $order ),
		'items'           => array(
			array( 'order_item_id' => $order * 10, 'product_id' => 900, 'variation_id' => 0, 'name' => 'On demand', 'quantity_scaled' => $qty_units * 1000000, 'quantity_scale' => 1000000, 'revenue_minor' => 3000 * $qty_units, 'tax_minor' => 0 ),
		),
		'shipping_minor'  => 0,
		'fees_minor'      => 0,
		'tax_minor'       => 0,
		'total_minor'     => 3000 * $qty_units,
	);
};

mda_assert_throws( static fn() => MDA_Sales_Service::post_order_payload( $sale_payload( 7001, 2 ) ), 'stock product without stock must still be refused', 'سفارشی از تأمین‌کننده' );

MDA_Supply_Service::save_settings( 900, 0, MDA_Supply_Service::MODE_ON_DEMAND, 3, $supplier );
$sale = MDA_Sales_Service::post_order_payload( $sale_payload( 7002, 2 ) );
mda_assert( 0 === (int) $sale['cogs_total_minor'], 'on-demand sale without stock posts no COGS yet' );
mda_assert( 2000000 === MDA_Supply_Service::open_quantity( $db, 900, 0, $warehouse ), 'backorder of 2 units recorded' );

$draft = MDA_Purchase_Service::create_draft(
	array(
		'supplier_party_id' => $supplier,
		'warehouse_id'      => $warehouse,
		'document_date'     => '2026-11-17',
		'currency_code'     => 'IRR',
		'idempotency_key'   => 'test-purchase-ondemand-01',
		'lines'             => array( array( 'product_id' => 900, 'variation_id' => 0, 'product_name_snapshot' => 'On demand', 'sku_snapshot' => 'OD', 'quantity_scaled' => 3000000, 'quantity_scale' => 1000000, 'unit_cost_minor' => 2000 ) ),
	)
);
MDA_Purchase_Service::post( $draft );
mda_assert( 0 === MDA_Supply_Service::open_quantity( $db, 900, 0, $warehouse ), 'purchase fulfilled the backorder' );
$state = $db->query( 'SELECT quantity_scaled FROM mda_product_cost_state WHERE product_id=900' )->fetch();
mda_assert( 1000000 === (int) $state['quantity_scaled'], 'one unit left in stock after fulfilling two' );
$ledger = (int) $db->query( 'SELECT SUM(quantity_scaled) FROM mda_inventory_movements WHERE product_id=900' )->fetchColumn();
mda_assert( 1000000 === $ledger, 'stock ledger matches cost state' );
$cogs = (int) $db->query( "SELECT SUM(e.debit_minor) FROM mda_journal_entries e JOIN mda_documents d ON d.id=e.document_id WHERE d.document_type='backorder_cogs'" )->fetchColumn();
mda_assert( 4000 === $cogs, 'COGS 2 x 2000 posted on fulfilment' );
$tb = $db->query( 'SELECT SUM(debit_minor) d, SUM(credit_minor) c FROM mda_journal_entries' )->fetch();
mda_assert( (int) $tb['d'] === (int) $tb['c'], 'trial balance stays balanced' );

// Remaining stock is used normally for the next sale, with COGS at average cost.
$next = MDA_Sales_Service::post_order_payload( $sale_payload( 7003, 1 ) );
mda_assert( 2000 === (int) $next['cogs_total_minor'], 'next sale uses stock and posts COGS immediately' );

echo "OK: balance guard, immutable general ledger, closed-period trigger, site fingerprint, on-demand backorder and fulfilment COGS passed.\n";
