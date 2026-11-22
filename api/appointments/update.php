<?php
/**
 * HealthBridge — Doctor Update Appointment
 * Doctors may confirm or cancel a pending appointment assigned to them.
 * Date/time changes must use the reschedule workflow.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentLifecycleRepository.php';

header('Content-Type: application/json; charset=utf-8');

$doctor = requireRole('doctor');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
if (!is_array($input)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid request body.']);
}
$appointmentId = parsePositiveInt($input['id'] ?? null);
$statusInput = $input['status'] ?? null;

if ($appointmentId === null || $appointmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}
if (!is_string($statusInput)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment status must be text.']);
}
$newStatus = trim($statusInput);
if (array_key_exists('date', $input) || array_key_exists('time', $input)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment date/time changes must use the reschedule workflow.']);
}
if (!in_array($newStatus, ['Confirmed', 'Cancelled'], true)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid status. A doctor can confirm or cancel a pending appointment.']);
}

try {
    $db = getDB();
    $lifecycle = new AppointmentLifecycleRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_APPOINTMENTS_SCHEMA'));
    $status = $newStatus === 'Confirmed' ? 'confirmed' : 'cancelled';
    $lifecycle->transitionPending($appointmentId, (int)$doctor['id'], 'doctor', $status);

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Doctor Update Appointment Outbox Dispatch Error: ' . get_class($notificationError));
    }
    jsonResponse(true, [
        'message' => $newStatus === 'Confirmed'
            ? 'Appointment confirmed successfully.'
            : 'Appointment cancelled successfully.',
        'status' => $newStatus,
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Appointment not found.') {
        http_response_code(404);
    } elseif (str_contains($message, 'assigned to you')) {
        http_response_code(403);
    } else {
        http_response_code(409);
    }
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Doctor Update Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update appointment.']);
}
