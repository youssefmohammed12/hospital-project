<?php
/**
 * HealthBridge — Admin: Delete Patient
 * Removes a patient account and cancels their active appointments.
 * POST { id }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';

requireRole('admin');

$data = getJsonInput();
$id   = (int)($data['id'] ?? 0);

if (!$id) {
    jsonResponse(false, ['message' => 'Invalid patient ID.']);
}

try {
    $db = getDB();

    // Verify patient exists
    $check = $db->prepare('SELECT id, name FROM users WHERE id = ? AND role = "patient" LIMIT 1');
    $check->execute([$id]);
    $patient = $check->fetch();

    if (!$patient) {
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    $db->beginTransaction();

    // Cancel active appointments for this patient
    $db->prepare(
        'UPDATE appointments SET status = "Cancelled"
         WHERE user_id = ? AND status IN ("Pending","Confirmed")'
    )->execute([$id]);

    // Delete patient account
    $db->prepare('DELETE FROM users WHERE id = ? AND role = "patient"')->execute([$id]);

    // Log to audit
    $audit = new AuditService($db, (int)$_SESSION['user_id'], $_SESSION['role']);
    $audit->log('delete', 'patient', $id, $patient['name'], null, "Patient '{$patient['name']}' deleted", $id);

    $db->commit();

    jsonResponse(true, ['message' => "Patient '{$patient['name']}' deleted successfully."]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Delete Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to delete patient. Please try again.']);
}

