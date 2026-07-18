<?php
/**
 * HealthBridge — Toggle Doctor Status
 * Activates or deactivates a doctor account and notifies them.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();
$doctorId = (int)($input['id'] ?? 0);

if ($doctorId <= 0) {
    jsonResponse(false, ['message' => 'Invalid doctor ID.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'doctor'");
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();

    if (!$doctor) {
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }

    $newStatus = $doctor['is_active'] ? 0 : 1;
    $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->execute([$newStatus, $doctorId]);

    $statusText = $newStatus ? 'activated' : 'deactivated';

    // Log the action
    try {
        $audit = new AdminAuditService($db, (int)$user['id']);
        $action = $newStatus ? 'activate' : 'deactivate';
        $statusDesc = $newStatus ? 'Account status changed: Inactive → Active' : 'Account status changed: Active → Inactive';
        $audit->log($action, 'doctor', $doctorId,
            $newStatus ? 'inactive' : 'active',
            $newStatus ? 'active' : 'inactive',
            $statusDesc,
            null,
            $doctorId
        );
    } catch (Exception $auditErr) {
        error_log('Audit log error (toggle_doctor): ' . $auditErr->getMessage());
    }

    // Notify the doctor
    $ns = new NotificationService($db);
    $ns->create(
        $doctorId,
        NotificationService::TYPE_ACCOUNT_STATUS_CHANGED,
        'Account Status Changed',
        "Your account has been {$statusText} by an administrator.",
        'user',
        $doctorId
    );

    jsonResponse(true, [
        'message' => "Doctor {$statusText} successfully.",
        'is_active' => $newStatus,
    ]);

} catch (Exception $e) {
    error_log('Toggle Doctor Status Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to toggle doctor status.']);
}
