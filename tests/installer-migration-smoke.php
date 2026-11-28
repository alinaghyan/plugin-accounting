<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'MDA_PATH' ) ) {
	define( 'MDA_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
require_once MDA_PATH . 'includes/class-mda-installer.php';

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
$pdo->exec( 'PRAGMA foreign_keys = ON' );
MDA_Database::use_connection( $pdo );

// Simulate a site already upgraded through migration 021.
$migrations = glob( MDA_PATH . 'database/migrations/*.sql' );
sort( $migrations, SORT_STRING );
foreach ( $migrations as $file ) {
	if ( '022-supplier-credit-notes-and-refunds.sql' === basename( $file ) ) {
		continue;
	}
	$sql = file_get_contents( $file );
	if ( false === $sql ) {
		throw new RuntimeException( 'Migration file could not be read: ' . basename( $file ) );
	}
	$pdo->exec( $sql );
}

$now = gmdate( 'Y-m-d H:i:s' );
$version = $pdo->prepare( "INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES ('schema_version',:version,:now)" );
$version->execute( array( ':version' => '21', ':now' => $now ) );

MDA_Installer::migrate();

$storedVersion = (int) $pdo->query( "SELECT setting_value FROM mda_settings WHERE setting_key='schema_version'" )->fetchColumn();
mda_assert( 22 === MDA_Installer::SCHEMA_VERSION, 'Installer schema constant must be 22.' );
mda_assert( 22 === $storedVersion, 'Existing schema 21 database was not upgraded to schema 22.' );
mda_assert( 22 === (int) get_option( 'mda_schema_cache', 0 ), 'Cached schema version was not updated to 22.' );

$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table'" )->fetchAll( PDO::FETCH_COLUMN );
foreach ( array( 'mda_supplier_credit_notes', 'mda_purchase_refunds' ) as $table ) {
	mda_assert( in_array( $table, $tables, true ), 'Migration 022 did not create ' . $table . '.' );
}

$triggers = $pdo->query( "SELECT name FROM sqlite_master WHERE type='trigger'" )->fetchAll( PDO::FETCH_COLUMN );
foreach ( array(
	'trg_mda_supplier_credit_notes_no_update',
	'trg_mda_supplier_credit_notes_no_delete',
	'trg_mda_purchase_refunds_no_update',
	'trg_mda_purchase_refunds_no_delete',
) as $trigger ) {
	mda_assert( in_array( $trigger, $triggers, true ), 'Migration 022 did not create ' . $trigger . '.' );
}

MDA_Installer::migrate();
$storedVersionAfterRetry = (int) $pdo->query( "SELECT setting_value FROM mda_settings WHERE setting_key='schema_version'" )->fetchColumn();
mda_assert( 22 === $storedVersionAfterRetry, 'Repeated migration changed the schema version unexpectedly.' );

echo "OK: installer upgraded schema 21 to 22, created supplier credit/refund tables and append-only triggers, and remained idempotent.\n";
