<?php
/**
 * HealthBridge — Get Visit Draft Endpoint (Phase 5.4)
 * Retrieves autosave draft for visit notes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';

header('Content-Type: application/json');

$user = requireAuth();

if ($user['role'] !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can access visit drafts.']);
}

$appointmentId = (int)($_GET['appointment_id'] ?? 0);

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
        jsonResponse(false, ['message' => 'You can only access drafts for your own appointments.']);
    }

    $draftService = new VisitDraftService($db);
    $draft = $draftService->get($appointmentId);

    jsonResponse(true, ['draft' => $draft]);

} catch (Exception $e) {
    error_log('Get Visit Draft Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to get draft.']);
}

