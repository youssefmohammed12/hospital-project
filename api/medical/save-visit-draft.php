<?php
/**
 * HealthBridge — Save Visit Draft Endpoint (Phase 5.4)
 * Saves autosave draft for visit notes in database.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();

if ($user['role'] !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can save visit drafts.']);
}

$appointmentId = (int)($input['appointment_id'] ?? 0);
$doctorId = (int)$user['id'];

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

try {
    $db = getDB();
    
    // Verify appointment belongs to this doctor
    $stmt = $db->prepare(
        "SELECT id FROM appointments WHERE id = ? AND doctor_id = ? LIMIT 1"
    );
    $stmt->execute([$appointmentId, $doctorId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only save drafts for your own appointments.']);
    }

    $draftService = new VisitDraftService($db);
    $draftService->save($appointmentId, $doctorId, $input);

    jsonResponse(true, ['message' => 'Draft saved.']);

} catch (Exception $e) {
    error_log('Save Visit Draft Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to save draft.']);
}

