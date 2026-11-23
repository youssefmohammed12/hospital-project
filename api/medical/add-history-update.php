<?php
/**
 * HealthBridge — Add Medical History Update Entry
 * Records doctor-authored changes in the schema-specific history timeline.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$doctorId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$patientId = parsePositiveInt($input['patient_id'] ?? null);
$field = $input['field'] ?? '';
$oldValue = $input['old_value'] ?? '';
$newValue = $input['new_value'] ?? '';

if (!is_string($field)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid medical record field.']);
}
if (
    ($oldValue !== null && !is_string($oldValue) && !is_numeric($oldValue))
    || ($newValue !== null && !is_string($newValue) && !is_numeric($newValue))
) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Medical history values must be text or numbers.']);
}
$oldValue = $oldValue === null ? '' : (string)$oldValue;
$newValue = $newValue === null ? '' : (string)$newValue;
if (mb_strlen($oldValue) > 10000 || mb_strlen($newValue) > 10000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Medical history values must not exceed 10000 characters.']);
}
if ($patientId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

try {
    $db = getDB();
    $repository = new PatientManagementRepository($db);
    $repository->recordMedicalHistoryUpdate(
        $patientId,
        $doctorId,
        $field,
        $oldValue,
        $newValue
    );

    // In v2 the profile update itself atomically writes this history event.
    // The repository acknowledges that recent event to avoid a duplicate.
    jsonResponse(true, ['message' => 'Medical history update recorded.']);
} catch (DomainException $e) {
    http_response_code(403);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Add Medical History Update Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to record medical history update.']);
}
