<?php
/**
 * HealthBridge — Cancel Prescription
 * Cancels an Active prescription with a required reason.
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
$reasonInput    = $input['reason'] ?? null;
if (!is_string($reasonInput)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Cancellation reason must be text.']);
}
$reason = trim($reasonInput);
if (mb_strlen($reason) > 2000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Cancellation reason must not exceed 2000 characters.']);
}

if ($prescriptionId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

if (empty($reason)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Cancellation reason is required.']);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $cancelled = $ps->cancel($prescriptionId, $currentUserId, $reason);

    if (!$cancelled) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot cancel this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    jsonResponse(true, ['message' => 'Prescription cancelled successfully.', 'prescription_id' => $prescriptionId]);

} catch (Throwable $e) {
    error_log('Cancel Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to cancel prescription.']);
}
