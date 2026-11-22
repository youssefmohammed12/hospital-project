<?php
/**
 * HealthBridge — Admin: Archive Doctor
 *
 * Disables sign-in while retaining the doctor's profile, schedule, clinical
 * records and audit history. Only untouched appointments are cancelled.
 * POST { id }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$id = parsePositiveInt($data['id'] ?? null);

if ($id <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid doctor ID.']);
}

try {
    $db = getDB();

    // The live application remains on v1. The repository has a separately
    // tested v2 adapter; changing this mode belongs to the approved cutover.
    $management = new DoctorManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_DOCTORS_SCHEMA'));
    $result = $management->archiveDoctor($id, (int)$user['id']);

    if (!$result['changed']) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'This doctor account is already disabled.']);
    }

    // The repository has committed the doctor transition and cancellation
    // notices. Dispatch is best-effort; durable outbox rows remain retryable.
    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $dispatchError) {
        error_log('Doctor archive committed; notification dispatch deferred: ' . get_class($dispatchError));
    }

    jsonResponse(true, [
        'message' => "Dr. '{$result['doctor_name']}' archived. Sign-in is disabled; clinical and audit history has been retained.",
        'cancelled_appointments' => (int)$result['cancelled_appointments'],
        'is_active' => 0,
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Doctor account not found.') {
        http_response_code(404);
        $message = 'Doctor not found.';
    } elseif (str_contains($message, 'active visit')) {
        http_response_code(409);
    } else {
        http_response_code(403);
        $message = 'An active administrator is required to archive a doctor account.';
    }
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Archive Doctor Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to archive doctor. Please try again.']);
}
