<?php
/**
 * Custom order statuses (cleaning, protection of statuses in use, sale statuses) and warehouse
 * quantity parsing.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $c ) ? $c : null; }
}
if ( ! function_exists( '_x' ) ) {
	function _x( $t ) { return $t; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand() { return random_int( 0, 999999 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mda-jalali.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-order-statuses.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-warehouse-service.php';

$d = MDA_Order_Statuses::defaults();
mda_assert( 'return' === $d['custom']['mda-returned']['behaviour'], 'default «مرجوع شده» reverses the sale' );
foreach ( array_keys( $d['custom'] ) as $k ) {
	mda_assert( strlen( 'wc-' . $k ) <= 20, "post_status key $k fits 20 chars" );
}

$existing = $d['custom'];
$clean    = MDA_Order_Statuses::clean(
	array(
		'custom' => array(
			array( 'key' => 'mda-returned', 'label' => '', 'in_use' => 1 ),          // In use: kept even when emptied.
			array( 'key' => 'mda-cancel-ref', 'label' => '', 'in_use' => 0 ),        // Unused + empty: dropped.
			array( 'key' => '', 'label' => 'ارسال شده', 'color' => '#2563eb', 'behaviour' => 'sale', 'paid' => 1 ),
			array( 'key' => '', 'label' => 'نامعتبر', 'color' => 'red', 'behaviour' => 'hack' ),
			array( 'key' => '', 'label' => '' ),
		),
		'rename' => array( 'processing' => 'در حال آماده‌سازی', 'bogus' => 'x', 'completed' => '' ),
	),
	$existing
);
mda_assert( isset( $clean['custom']['mda-returned'] ) && 'مرجوع شده' === $clean['custom']['mda-returned']['label'], 'status with orders cannot be removed' );
mda_assert( ! isset( $clean['custom']['mda-cancel-ref'] ), 'unused status removed' );
$new = array_values( array_filter( $clean['custom'], static fn( $s ) => 'ارسال شده' === $s['label'] ) );
mda_assert( 1 === count( $new ) && 'sale' === $new[0]['behaviour'] && 1 === $new[0]['paid'], 'new sale status kept with paid flag' );
$bad = array_values( array_filter( $clean['custom'], static fn( $s ) => 'نامعتبر' === $s['label'] ) );
mda_assert( 'none' === $bad[0]['behaviour'] && '#6b6880' === $bad[0]['color'], 'unknown behaviour/color fall back safely' );
foreach ( array_keys( $clean['custom'] ) as $k ) {
	mda_assert( strlen( 'wc-' . $k ) <= 20 && preg_match( '/^[a-z0-9-]+$/', $k ), "generated key $k is a valid post status" );
}
mda_assert( array( 'processing' => 'در حال آماده‌سازی' ) === $clean['rename'], 'only real core statuses are renamed' );

update_option( MDA_Order_Statuses::OPTION, $clean );
$sale = MDA_Order_Statuses::sale_statuses();
mda_assert( in_array( 'processing', $sale, true ) && in_array( 'completed', $sale, true ) && count( $sale ) === 3, 'custom sale status posts like completed' );
mda_assert( ! in_array( 'mda-returned', $sale, true ), 'return status never posts a sale' );

mda_assert( 2500000 === MDA_Warehouse_Service::scale_quantity( '۲٫۵' ), 'Persian decimal quantity' );
mda_assert( 1000000 === MDA_Warehouse_Service::scale_quantity( '1' ), 'integer quantity' );
mda_assert_throws( static fn() => MDA_Warehouse_Service::scale_quantity( '0' ), 'zero rejected' );
mda_assert_throws( static fn() => MDA_Warehouse_Service::scale_quantity( '-3' ), 'negative rejected' );

echo "statuses-warehouses-smoke: OK\n";
