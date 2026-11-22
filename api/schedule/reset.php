<?php
/**
 * HealthBridge — Reset Doctor Schedule to Default
 *
 * Only admins can reset a doctor's schedule to factory defaults.
 *
 * POST: Expects JSON body with:
 *   doctor_id: int (required)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();

$doctorId = parsePositiveInt($input['doctor_id'] ?? null);
if ($doctorId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid doctor ID is required.']);
}

// Validate doctor exists
$stmt = getDB()->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'doctor'");
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch();
if (!$doctor) {
    http_response_code(404);
    jsonResponse(false, ['message' => 'Doctor not found.']);
    exit;
}

try {
    $db = getDB();
    $ss = new ScheduleService($db, ScheduleService::schemaFromEnvironment());
    $ss->resetToDefault($doctorId);

    jsonResponse(true, [
        'message' => 'Schedule reset to defaults for ' . $doctor['name'] . '.',
    ]);
} catch (Exception $e) {
    error_log('Schedule reset error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to reset schedule.']);
}
