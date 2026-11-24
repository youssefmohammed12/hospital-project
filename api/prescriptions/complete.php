<?php
/**
 * HealthBridge — Complete Prescription
 * Marks an Active prescription as Completed (read-only).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$currentUserId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$prescriptionId = parsePositiveInt($input['prescription_id'] ?? null);

if ($prescriptionId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $completed = $ps->complete($prescriptionId, $currentUserId);

    if (!$completed) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot complete this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    jsonResponse(true, ['message' => 'Prescription marked as completed.', 'prescription_id' => $prescriptionId]);

} catch (Throwable $e) {
    error_log('Complete Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to complete prescription.']);
}
