<?php
/**
 * HealthBridge — Delete Visit Draft Endpoint (Phase 5.4)
 * Deletes autosave draft for visit notes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();

if ($user['role'] !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can delete visit drafts.']);
}

$appointmentId = (int)($input['appointment_id'] ?? 0);

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

try {
    $db = getDB();
    
    // Verify appointment belongs to this doctor
    $stmt = $db->prepare(
        "SELECT id FROM appointments WHERE id = ? AND doctor_id = ? LIMIT 1"
    );
    $stmt->execute([$appointmentId, (int)$user['id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only delete drafts for your own appointments.']);
    }

    $draftService = new VisitDraftService($db);
    $draftService->delete($appointmentId);

    jsonResponse(true, ['message' => 'Draft deleted.']);

} catch (Exception $e) {
    error_log('Delete Visit Draft Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to delete draft.']);
}

