<?php
/**
 * HealthBridge — Admin Approve Appointment
 * Transitions one pending booking and creates its initial clinical workflow.
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
if ($appointmentId === null || $appointmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

try {
    $db = getDB();
    $lifecycle = new AppointmentLifecycleRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_APPOINTMENTS_SCHEMA'));
    $result = $lifecycle->transitionPending(
        $appointmentId,
        (int)$admin['id'],
        'admin',
        'confirmed'
    );

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Approve Appointment Outbox Dispatch Error: ' . get_class($notificationError));
    }
    jsonResponse(true, ['message' => 'Appointment approved successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code($message === 'Appointment not found.' ? 404 : 409);
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Approve Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to approve appointment.']);
}
