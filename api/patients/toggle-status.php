<?php
/**
 * HealthBridge — Toggle Patient Status
 * Activates or deactivates a patient account and notifies them.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';

header('Content-Type: application/json');

$input = getJsonInput();
$patientId = (int)($input['id'] ?? 0);

if ($patientId <= 0) {
    jsonResponse(false, ['message' => 'Invalid patient ID.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'patient'");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    $newStatus = $patient['is_active'] ? 0 : 1;
    $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->execute([$newStatus, $patientId]);

    $statusText = $newStatus ? 'activated' : 'deactivated';
    $action = $newStatus ? 'activate' : 'deactivate';

    // Log to audit
    $audit = new AuditService($db, (int)$_SESSION['user_id'], $_SESSION['role']);
    $audit->log($action, 'patient', $patientId, $newStatus ? 'inactive' : 'active', $newStatus ? 'active' : 'inactive', null, $patientId);

    // Notify the patient
    $ns = new NotificationService($db);
    $ns->create(
        $patientId,
        NotificationService::TYPE_ACCOUNT_STATUS_CHANGED,
        'Account Status Changed',
        "Your account has been {$statusText} by an administrator.",
        'user',
        $patientId
    );

    jsonResponse(true, [
        'message' => "Patient {$statusText} successfully.",
        'is_active' => $newStatus,
    ]);

} catch (Exception $e) {
    error_log('Toggle Patient Status Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to toggle patient status.']);
}
