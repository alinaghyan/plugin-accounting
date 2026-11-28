<?php
declare(strict_types=1);

// Reuse the isolated purchase/return fixture and its complete migration chain.
require_once __DIR__ . '/purchase-settlements-returns-smoke.php';
require_once dirname(__DIR__) . '/includes/class-mda-purchase-credit-service.php';

$db = MDA_Database::connection();
$purchaseId = (int) $purchase;
$returns = $db->prepare('SELECT id,total_minor FROM mda_purchase_returns WHERE purchase_id=:purchase ORDER BY id');
$returns->execute(array(':purchase' => $purchaseId));
$returnRows = $returns->fetchAll();
if (count($returnRows) !== 2 || (int) $returnRows[0]['total_minor'] !== 50 || (int) $returnRows[1]['total_minor'] !== 150) {
    throw new RuntimeException('Supplier credit-note fixture does not contain the expected posted returns.');
}
$returnId1 = (int) $returnRows[0]['id'];
$returnId2 = (int) $returnRows[1]['id'];

$note1 = MDA_Purchase_Credit_Service::record_credit_note($returnId2, 'SUP-CN-001', 150, 'supplier-credit-note-test-001', '2026-11-17');
if (!empty($note1['duplicate']) || (int) $note1['amount_minor'] !== 150) {
    throw new RuntimeException('Supplier credit note was not recorded correctly.');
}
$duplicateNote = MDA_Purchase_Credit_Service::record_credit_note($returnId2, 'SUP-CN-001', 150, 'supplier-credit-note-test-001', '2026-11-17');
if (empty($duplicateNote['duplicate']) || (int) $duplicateNote['credit_note_id'] !== (int) $note1['credit_note_id']) {
    throw new RuntimeException('Supplier credit-note idempotency failed.');
}
$creditMismatchRejected = false;
try {
    MDA_Purchase_Credit_Service::record_credit_note($returnId2, 'SUP-CN-WRONG', 149, 'supplier-credit-note-wrong-001', '2026-11-17');
} catch (InvalidArgumentException $e) {
    $creditMismatchRejected = true;
}
if (!$creditMismatchRejected) {
    throw new RuntimeException('A credit note with a value different from the posted return was accepted.');
}

$cash = (int) $db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn();
$inventory = (int) $db->query("SELECT id FROM mda_accounts WHERE account_code='1300'")->fetchColumn();
$cashBalanceBefore = $cashBalance();
$refund1 = MDA_Purchase_Credit_Service::record_refund((int) $note1['credit_note_id'], 150, $cash, 'bank', 'supplier-refund-test-001', '2026-11-17');
if (!empty($refund1['duplicate']) || (int) $refund1['remaining_minor'] !== 0 || $cashBalance() !== $cashBalanceBefore + 150) {
    throw new RuntimeException('Supplier refund amount or cash journal balance is incorrect.');
}
$refundDocuments = (int) $db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_refund'")->fetchColumn();
$duplicateRefund = MDA_Purchase_Credit_Service::record_refund((int) $note1['credit_note_id'], 150, $cash, 'bank', 'supplier-refund-test-001', '2026-11-17');
if (empty($duplicateRefund['duplicate']) || (int) $db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_refund'")->fetchColumn() !== $refundDocuments) {
    throw new RuntimeException('Supplier refund idempotency created a duplicate journal.');
}
$overRefundRejected = false;
try {
    MDA_Purchase_Credit_Service::record_refund((int) $note1['credit_note_id'], 1, $cash, 'cash', 'supplier-refund-over-001', '2026-11-17');
} catch (InvalidArgumentException $e) {
    $overRefundRejected = true;
}
if (!$overRefundRejected) {
    throw new RuntimeException('Supplier refund exceeded the confirmed credit-note balance.');
}
$invalidCashRejected = false;
try {
    MDA_Purchase_Credit_Service::record_refund((int) $note1['credit_note_id'], 1, $inventory, 'cash', 'supplier-refund-account-001', '2026-11-17');
} catch (InvalidArgumentException $e) {
    $invalidCashRejected = true;
}
if (!$invalidCashRejected) {
    throw new RuntimeException('Supplier refund accepted an inventory account as its cash/bank account.');
}

$note2 = MDA_Purchase_Credit_Service::record_credit_note($returnId1, 'SUP-CN-002', 50, 'supplier-credit-note-test-002', '2026-11-17');
$beforeRefundCount = (int) $db->query('SELECT COUNT(*) FROM mda_purchase_refunds')->fetchColumn();
$beforeDocumentCount = (int) $db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_refund'")->fetchColumn();
$beforeCashBalance = $cashBalance();
$db->exec("CREATE TRIGGER test_fail_supplier_refund_audit BEFORE INSERT ON mda_audit_logs WHEN NEW.action='supplier_refund_recorded' BEGIN SELECT RAISE(ABORT,'forced supplier refund audit failure'); END");
$refundRolledBack = false;
try {
    MDA_Purchase_Credit_Service::record_refund((int) $note2['credit_note_id'], 50, $cash, 'cash', 'supplier-refund-rollback-001', '2026-11-17');
} catch (PDOException $e) {
    $refundRolledBack = true;
}
$db->exec('DROP TRIGGER test_fail_supplier_refund_audit');
if (!$refundRolledBack
    || (int) $db->query('SELECT COUNT(*) FROM mda_purchase_refunds')->fetchColumn() !== $beforeRefundCount
    || (int) $db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_refund'")->fetchColumn() !== $beforeDocumentCount
    || $cashBalance() !== $beforeCashBalance) {
    throw new RuntimeException('Failed supplier refund did not roll back its refund row, journal and cash balance atomically.');
}

$refund2 = MDA_Purchase_Credit_Service::record_refund((int) $note2['credit_note_id'], 50, $cash, 'cash', 'supplier-refund-rollback-001', '2026-11-17');
if (!empty($refund2['duplicate']) || (int) $refund2['remaining_minor'] !== 0 || $cashBalance() !== $beforeCashBalance + 50) {
    throw new RuntimeException('Supplier refund did not succeed after the forced rollback was removed.');
}
$closedPeriodRejected = false;
try {
    MDA_Purchase_Credit_Service::record_refund((int) $note1['credit_note_id'], 1, $cash, 'cash', 'supplier-refund-closed-001', '2026-11-16');
} catch (RuntimeException $e) {
    $closedPeriodRejected = strpos($e->getMessage(), 'دوره مالی بسته') !== false;
}
if (!$closedPeriodRejected) {
    throw new RuntimeException('Supplier refund was not blocked in a closed financial period.');
}

foreach (array('mda_supplier_credit_notes', 'mda_purchase_refunds') as $table) {
    try {
        $db->exec("UPDATE {$table} SET amount_minor=1");
        throw new RuntimeException($table . ' update immutability failed.');
    } catch (PDOException $expected) {
    }
    try {
        $db->exec("DELETE FROM {$table}");
        throw new RuntimeException($table . ' delete immutability failed.');
    } catch (PDOException $expected) {
    }
}
$unbalanced = (int) $db->query("SELECT COUNT(*) FROM (SELECT d.id FROM mda_documents d JOIN mda_journal_entries j ON j.document_id=d.id WHERE d.document_type='supplier_refund' GROUP BY d.id HAVING SUM(j.debit_minor)<>SUM(j.credit_minor))")->fetchColumn();
if ($unbalanced !== 0 || (int) $db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_refund'")->fetchColumn() !== 2) {
    throw new RuntimeException('Supplier refund journal documents are missing or unbalanced.');
}

echo "OK: supplier credit-note valuation, idempotency, refund limits, cash/bank validation, closed periods, atomic rollback, balanced journals and append-only history passed.\n";
