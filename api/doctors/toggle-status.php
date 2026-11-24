<?php
/**
 * HealthBridge — Toggle Doctor Status
 *
 * Disabling revokes existing sessions, blocks new public bookings, cancels
 * untouched appointments, and refuses while unfinished clinical work exists.
 * Re-enabling permits fresh sign-in.
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

$input = getJsonInput();
$doctorId = parsePositiveInt($input['id'] ?? null);

if ($doctorId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid doctor ID.']);
}

try {
    $db = getDB();

    // The live application remains on v1. The repository has a separately
    // tested v2 adapter; changing this mode belongs to the approved cutover.
    $management = new DoctorManagementRepository($db);
    $result = $management->toggleDoctorActive($doctorId, (int)$user['id']);

    $newStatus = (int)$result['is_active'];
    $statusText = $newStatus === 1 ? 'activated' : 'deactivated';

    // Status and appointment notifications were enqueued in the same database
    // transaction as the transition. Dispatch is best-effort after commit.
    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $dispatchError) {
        error_log('Doctor status committed; notification dispatch deferred: ' . get_class($dispatchError));
    }

    jsonResponse(true, [
        'message' => "Doctor {$statusText} successfully.",
        'is_active' => $newStatus,
        'cancelled_appointments' => (int)$result['cancelled_appointments'],
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
        $message = 'An active administrator is required to change doctor status.';
    }
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Toggle Doctor Status Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update doctor status.']);
}
