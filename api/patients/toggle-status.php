<?php
/**
 * HealthBridge — Toggle Patient Status
 * Admin-only reversible account access toggle; revokes existing sessions.
 * POST { id }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$patientId = parsePositiveInt($input['id'] ?? null);
if ($patientId === null || $patientId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid patient ID.']);
}

try {
    $db = getDB();
    $management = new PatientManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PATIENTS_SCHEMA'));
    $result = $management->togglePatientActive($patientId, (int)$user['id']);

    $statusText = (int)$result['is_active'] === 1 ? 'activated' : 'deactivated';
    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $dispatchError) {
        error_log('Patient status committed; notification dispatch deferred: ' . get_class($dispatchError));
    }

    jsonResponse(true, [
        'message' => "Patient {$statusText} successfully.",
        'is_active' => (int)$result['is_active'],
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code($message === 'Patient not found.' ? 404 : 403);
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Toggle Patient Status Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to toggle patient status.']);
}
