<?php
/**
 * HealthBridge — Toggle Doctor Operational Availability
 * V1 keeps legacy profile/schedule availability synchronized. V2 changes only
 * the operational schedule flag and never changes accepts_new_patients.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorSettingsRepository.php';

$user = requireRole('doctor');
$userId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

try {
    $db = getDB();
    $settings = new DoctorSettingsRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_SCHEDULE_SCHEMA'));
    $result = $settings->toggleOperationalAvailability($userId, $userId);
    $available = (int)$result['available'];

    jsonResponse(true, [
        'message' => $available === 1
            ? 'You are now marked as available.'
            : 'You are now marked as unavailable.',
        'available' => $available,
        'doctor_name' => (string)$result['doctor_name'],
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code($message === 'Doctor profile not found.' ? 404 : 403);
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Toggle Doctor Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update availability status.']);
}
