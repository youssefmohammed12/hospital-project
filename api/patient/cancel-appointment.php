<?php
/**
 * HealthBridge — Patient Cancel Appointment
 * Allows a patient to cancel their own future Pending or Confirmed appointment.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireRole('patient');
$userId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['id'] ?? null);
if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

try {
    $db = getDB();

    // Repository applies doctor-first lock ordering and schema-specific status rules.
    // Runtime stays on v1 until the explicit v2 cutover decision.
    $management = new PatientManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PATIENTS_SCHEMA'));
    $management->cancelAppointmentByPatient($appointmentId, $userId);

    // The cancellation and durable doctor notice are committed together.
    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Patient Cancel Appointment Outbox Dispatch Error: ' . get_class($notificationError));
    }

    jsonResponse(true, ['message' => 'Appointment cancelled successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Appointment not found.') {
        http_response_code(404);
    } elseif ($message === 'The authenticated account is not active.') {
        http_response_code(403);
    } else {
        http_response_code(409);
    }
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Patient Cancel Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to cancel appointment.']);
}
