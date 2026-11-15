<?php
/**
 * Standalone SQLite schema smoke test.
 * Run: php tests/sqlite-schema-smoke.php
 */
declare(strict_types=1);

if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "SKIP: pdo_sqlite is not installed.\n" );
	exit( 2 );
}

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'PRAGMA foreign_keys = ON' );
$sql = file_get_contents( dirname( __DIR__ ) . '/database/migrations/001_initial.sql' );
if ( false === $sql ) {
	throw new RuntimeException( 'Migration file not found.' );
}
$pdo->exec( $sql );

$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table'" )->fetchAll( PDO::FETCH_COLUMN );
foreach ( array( 'mda_settings', 'mda_parties', 'mda_accounts', 'mda_documents', 'mda_journal_entries', 'mda_inventory_movements', 'mda_integration_outbox', 'mda_audit_logs' ) as $table ) {
	if ( ! in_array( $table, $tables, true ) ) {
		throw new RuntimeException( 'Missing table: ' . $table );
	}
}

$now = gmdate( 'Y-m-d H:i:s' );
$pdo->exec( "INSERT INTO mda_accounts (account_code, account_name, account_type, created_at) VALUES ('1000','Cash','asset','{$now}'),('4000','Sales','income','{$now}')" );
$pdo->exec( "INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('SMOKE-1','sale','posted','smoke-key-0001','hash','IRR','2026-01-01','{$now}','{$now}')" );
$pdo->exec( "INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) VALUES (1,1,1,50000,0,'{$now}'),(1,2,2,0,50000,'{$now}')" );
$total = (int) $pdo->query( 'SELECT SUM(debit_minor) FROM mda_journal_entries' )->fetchColumn();
if ( 50000 !== $total ) {
	throw new RuntimeException( 'Journal smoke test failed.' );
}

echo "OK: SQLite schema and balanced journal row insertion passed.\n";
