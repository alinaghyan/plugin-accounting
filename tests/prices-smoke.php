<?php
/**
 * Price manager service: input cleaning, bulk computations, validation, history and level fallback.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-jalali.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-price-service.php';

function wc_format_decimal( $v ) { return rtrim( rtrim( number_format( (float) $v, 4, '.', '' ), '0' ), '.' ); }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }

class WC_Product {
	public array $meta = array();
	public string $regular = '';
	public string $sale = '';
	public int $saves = 0;
	public function __construct( public int $id, public int $parent = 0 ) {}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function get_name() { return 'P' . $this->id; }
	public function is_type( $t ) { return 'variation' === $t ? $this->parent > 0 : false; }
	public function get_regular_price( $c = 'view' ) { return $this->regular; }
	public function get_sale_price( $c = 'view' ) { return $this->sale; }
	public function get_price( $c = 'view' ) { return '' !== $this->sale ? $this->sale : $this->regular; }
	public function set_regular_price( $v ) { $this->regular = (string) $v; }
	public function set_sale_price( $v ) { $this->sale = (string) $v; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { ++$this->saves; return $this->id; }
}

$db = mda_test_database();
$p  = new WC_Product( 10 );
$p->regular = '1000000';
$GLOBALS['products'][10] = $p;

mda_assert( '1250000' === MDA_Price_Service::clean( '۱٬۲۵۰٬۰۰۰' ), 'Persian digits and separators' );
mda_assert( '' === MDA_Price_Service::clean( '' ), 'empty clears' );
mda_assert_throws( static fn() => MDA_Price_Service::clean( '12a' ), 'garbage rejected' );

$done = MDA_Price_Service::apply( $p, array( 'purchase' => '700,000', 'p1' => '900000', 'regular' => '1000000' ), 'grid' );
mda_assert( 2 === count( $done ) && 1 === $p->saves, 'only changed fields saved once' );
mda_assert( 2 === (int) $db->query( 'SELECT COUNT(*) FROM mda_price_history WHERE product_id=10' )->fetchColumn(), 'history logged' );
mda_assert_throws( static fn() => $db->exec( 'DELETE FROM mda_price_history' ), 'history append-only', 'append-only' );
mda_assert_throws( static fn() => MDA_Price_Service::apply( $p, array( 'sale' => '1200000' ), 'grid' ), 'sale must be below regular', 'کمتر' );

// Bulk computations.
mda_assert( '1100000' === MDA_Price_Service::compute( $p, 'regular', 'inc_pct', '10', '', 0 ), '+10%' );
mda_assert( '1100000' === MDA_Price_Service::compute( $p, 'regular', 'inc_pct', '7', '', 100000 ), '+7% rounded to 100k' );
mda_assert( '840000' === MDA_Price_Service::compute( $p, 'regular', 'margin', '20', '', 0 ), 'purchase + 20% margin' );
mda_assert( '855000' === MDA_Price_Service::compute( $p, 'p2', 'copy', '-5', 'p1', 0 ), 'copy from level 1 minus 5%' );
mda_assert( null === MDA_Price_Service::compute( $p, 'p2', 'inc_pct', '10', '', 0 ), 'percentage on empty value skipped' );
mda_assert( '' === MDA_Price_Service::compute( $p, 'p1', 'clear', '', '', 0 ), 'clear' );

// Variation falls back to the parent's level price.
$v = new WC_Product( 11, 10 );
$v->regular = '1000000';
$GLOBALS['products'][11] = $v;
mda_assert( '900000' === MDA_Price_Service::level_price( $v, 'p1' ), 'variation inherits parent level price' );
mda_assert( '1000000' === MDA_Price_Service::level_price( $v, 'nope' ), 'unknown level → store price' );

// Levels configuration.
MDA_Price_Service::save_levels( array( array( 'key' => 'p1', 'label' => 'عمده', 'roles' => array( 'customer', 'administrator' ), 'min_qty' => '10' ), array( 'key' => '', 'label' => '' ), array( 'key' => '', 'label' => 'نماینده' ) ) );
$levels = MDA_Price_Service::levels();
mda_assert( 2 === count( $levels ) && array( 'customer' ) === $levels['p1']['roles'] && 10 === $levels['p1']['min_qty'], 'levels saved, admin role stripped' );

echo "OK: price cleaning, apply/validation, append-only history, bulk operations, level fallback and level settings passed.\n";
