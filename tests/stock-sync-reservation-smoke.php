<?php
/**
 * Stock sync keeps quantities WooCommerce reserved for unposted orders and applies relative changes.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
define( 'DAY_IN_SECONDS', 86400 );
require_once dirname( __DIR__ ) . '/includes/class-mda-ledger-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-supply-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-inventory-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-stock-sync-service.php';

class WooCommerce {}
final class ResProduct {
	public static array $stock = array( 600 => 10.0, 601 => 5.0 );
	public function __construct( public int $id ) {}
	public function managing_stock() { return true; }
	public function get_manage_stock() { return true; }
	public function get_stock_quantity() { return self::$stock[ $this->id ]; }
}
final class ResItem {
	public function __construct( private int $product, private float $reduced ) {}
	public function get_product_id() { return $this->product; }
	public function get_variation_id() { return 0; }
	public function get_meta( $key, $single = true ) { return '_reduced_stock' === $key ? $this->reduced : ''; }
}
final class ResOrder {
	public function __construct( private int $id, private array $items ) {}
	public function get_id() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
}
function wc_get_product( $id ) { return new ResProduct( (int) $id ); }
function wc_get_orders( $args ) {
	// Order 1 is on-hold (card-to-card), WooCommerce already deducted 2 units, accounting has not posted it.
	return (object) array( 'orders' => array( new ResOrder( 1, array( new ResItem( 600, 2 ) ) ) ), 'max_num_pages' => 1 );
}
function wc_update_product_stock( $product, $qty, $op ) {
	// Relative update, like WooCommerce's atomic SQL update.
	ResProduct::$stock[ $product->id ] += 'increase' === $op ? $qty : -$qty;
	return ResProduct::$stock[ $product->id ];
}

$db = mda_test_database();
$w  = (int) $db->query( "SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'" )->fetchColumn();
foreach ( array( 600 => 12, 601 => 5 ) as $product => $units ) {
	$db->exec( "INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at) VALUES ($product,0,$w,'opening'," . ( $units * 1000000 ) . ",1000000,100,'IRR','opening','t','open-$product-0001','2026-11-16')" );
	$db->exec( "INSERT INTO mda_product_cost_state (product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,average_cost_minor,currency_code,updated_at) VALUES ($product,0,$w," . ( $units * 1000000 ) . ",1000000,100,'IRR','2026-11-16')" );
}

$run = MDA_Stock_Sync_Service::run_batch( $w, 100 );
mda_assert( 2 === $run['synced'], 'both products synced' );
// SQLite 12 − 2 reserved by the unposted on-hold order = 10 → WooCommerce must stay at 10, not jump to 12.
mda_assert( 10.0 === ResProduct::$stock[600], 'reserved on-hold quantity is preserved (no oversell)' );
mda_assert( 5.0 === ResProduct::$stock[601], 'unchanged product untouched' );

// Purchase adds 3 units in accounting; meanwhile a new order deducted 1 unit in WooCommerce (relative update keeps it).
$db->exec( "INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at) VALUES (601,0,$w,'purchase_receipt',3000000,1000000,100,'IRR','purchase','p','pur-601-0001','2026-11-16')" );
$db->exec( "UPDATE mda_product_cost_state SET quantity_scaled=8000000 WHERE product_id=601" );
$run = MDA_Stock_Sync_Service::run_batch( $w, 100 );
mda_assert( 8.0 === ResProduct::$stock[601], 'purchase increase applied as +3' );

// On-demand products are never pushed to WooCommerce.
MDA_Supply_Service::save_settings( 600, 0, MDA_Supply_Service::MODE_ON_DEMAND, 2, null );
ResProduct::$stock[600] = 0.0;
$run = MDA_Stock_Sync_Service::run_batch( $w, 100 );
mda_assert( 0.0 === ResProduct::$stock[600] && 1 === $run['skipped'], 'on-demand product skipped' );

echo "OK: stock sync reservations, relative updates and on-demand skip passed.\n";
