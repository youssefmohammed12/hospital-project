<?php
/**
 * HealthBridge — Admin Decline Appointment
 * Cancels a pending booking and retains the optional decline reason in audit
 * and patient notification records.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentLifecycleRepository.php';

$admin = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = is_array($input) ? parsePositiveInt($input['id'] ?? null) : null;
$reason = is_array($input) ? ($input['reason'] ?? '') : null;

if ($appointmentId === null || $appointmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}
if (!is_string($reason)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Decline reason must be text.']);
}
$reason = trim($reason);
if (mb_strlen($reason) > 2000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Decline reason must not exceed 2000 characters.']);
}

try {
    $db = getDB();
    $lifecycle = new AppointmentLifecycleRepository($db);
    $lifecycle->transitionPending($appointmentId, (int)$admin['id'], 'admin', 'cancelled', $reason);

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Decline Appointment Outbox Dispatch Error: ' . get_class($notificationError));
    }
    jsonResponse(true, ['message' => 'Appointment declined successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code($message === 'Appointment not found.' ? 404 : 409);
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Decline Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to decline appointment.']);
}
