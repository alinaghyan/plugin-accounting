<?php
/**
 * Shared test bootstrap: minimal WordPress stubs + the real MDA_Database class on an in-memory SQLite.
 */
declare(strict_types=1);

if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "SKIP: pdo_sqlite is not installed.\n" );
	exit( 2 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['mda_test_options'] = array();
$GLOBALS['mda_test_filters'] = array();
$GLOBALS['mda_test_user']    = 1;

function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_email( $v ) { return filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) ? (string) $v : ''; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function get_current_user_id() { return (int) $GLOBALS['mda_test_user']; }
function get_current_blog_id() { return 1; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $time, $hook, $args = array() ) { return true; }
function get_option( $key, $default = false ) { return $GLOBALS['mda_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mda_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['mda_test_options'][ $key ] ); return true; }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['mda_test_filters'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['mda_test_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['mda_test_filters'][ $hook ] ?? array() as $cb ) { $cb( ...$args ); } }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { return add_filter( $hook, $cb, $p, $a ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', (string) $v ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }

require_once dirname( __DIR__ ) . '/includes/class-mda-database.php';

/**
 * Fresh in-memory database with all migrations applied through the real installer order.
 */
function mda_test_database(): PDO {
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
	$pdo->exec( 'PRAGMA foreign_keys=ON' );
	foreach ( glob( dirname( __DIR__ ) . '/database/migrations/*.sql' ) as $file ) {
		$pdo->exec( (string) file_get_contents( $file ) );
	}
	MDA_Database::use_connection( $pdo );
	return $pdo;
}

function mda_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function mda_assert_throws( callable $fn, string $message, string $needle = '' ): void {
	try {
		$fn();
	} catch ( Throwable $e ) {
		if ( '' === $needle || false !== strpos( $e->getMessage(), $needle ) ) {
			return;
		}
		fwrite( STDERR, "FAIL: {$message} (unexpected error: {$e->getMessage()})\n" );
		exit( 1 );
	}
	fwrite( STDERR, "FAIL: {$message} (no exception)\n" );
	exit( 1 );
}

function mda_account_id( PDO $db, string $code ): int {
	$q = $db->prepare( 'SELECT id FROM mda_accounts WHERE account_code = :c' );
	$q->execute( array( ':c' => $code ) );
	return (int) $q->fetchColumn();
}
