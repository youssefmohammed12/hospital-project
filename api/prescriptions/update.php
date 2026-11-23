<?php
/**
 * HealthBridge — Update Prescription
 * Updates an Active prescription's medication items and notes.
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
$notesInput     = $input['notes'] ?? '';
$items          = $input['items'] ?? [];

if (!is_string($notesInput)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription notes must be text.']);
}
$notes = trim($notesInput);
if (mb_strlen($notes) > 5000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription notes must not exceed 5000 characters.']);
}
if (!is_array($items)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Medication items must be provided as a list.']);
}

if ($prescriptionId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

$validationError = PrescriptionService::validateItems($items);
if ($validationError) {
    http_response_code(400);
    jsonResponse(false, ['message' => $validationError]);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $updated = $ps->update($prescriptionId, $items, $notes ?: null, $currentUserId);

    if (!$updated) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot update this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    jsonResponse(true, ['message' => 'Prescription updated successfully.', 'prescription_id' => $prescriptionId]);

} catch (Throwable $e) {
    error_log('Update Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update prescription.']);
}
