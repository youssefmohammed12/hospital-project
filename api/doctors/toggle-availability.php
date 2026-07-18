<?php
/**
 * HealthBridge — Toggle Doctor Availability
 * Flips the doctor's availability status between available (1) and unavailable (0).
 *
 * Updated for Phase 2: Also syncs with doctor_schedule_settings.is_available
 * so the schedule engine and old availability system stay in sync.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$userId = requireRole('doctor')['id'];

try {
    $db = getDB();

    $check = $db->prepare('SELECT d.id, d.name, d.available, d.user_id FROM doctors d WHERE d.user_id = ? LIMIT 1');
    $check->execute([$userId]);
    $doctor = $check->fetch();

    if (!$doctor) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor profile not found.']);
    }

    $newAvailability = 1 - (int)$doctor['available'];
    $oldAvailLabel = $doctor['available'] ? 'Available' : 'Unavailable';
    $newAvailLabel = $newAvailability ? 'Available' : 'Unavailable';

    // Update old doctors.available field
    $db->prepare('UPDATE doctors SET available = ? WHERE user_id = ?')->execute([$newAvailability, $userId]);

    // Update new doctor_schedule_settings.is_available field (Phase 1/2)
    $ss = new ScheduleService($db);
    $ss->setAvailability($userId, $newAvailability === 1);

    // Log the availability toggle
    try {
        $audit = new AuditService($db, $userId, 'doctor');
        $audit->log(
            $newAvailability ? 'activate' : 'deactivate',
            'doctor',
            $userId,
            $oldAvailLabel,
            $newAvailLabel,
            "Availability changed: {$oldAvailLabel} → {$newAvailLabel}",
            null,
            $userId
        );
    } catch (Exception $auditErr) {
        error_log('Audit log error (toggle_doctor_availability): ' . $auditErr->getMessage());
    }

    jsonResponse(true, [
        'message' => $newAvailability ? 'You are now marked as available.' : 'You are now marked as unavailable.',
        'available' => $newAvailability,
        'doctor_name' => $doctor['name']
    ]);

} catch (Exception $e) {
    error_log('Toggle Doctor Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update availability status.']);
}
