<?php
/**
 * HealthBridge — Add Medical History Update Entry
 * Records a medical history update for timeline tracking.
 * Called after a doctor updates clinical information.
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if ($currentRole !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can record medical history updates.']);
}

$input = getJsonInput();
$patientId = (int)($input['patient_id'] ?? 0);
$field = $input['field'] ?? '';
$oldValue = $input['old_value'] ?? '';
$newValue = $input['new_value'] ?? '';

if (!$patientId || !$field) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID and field are required.']);
}

try {
    $db = getDB();

    // Insert into medical_history_updates table
    $stmt = $db->prepare(
        "INSERT INTO medical_history_updates 
         (patient_id, doctor_id, field_name, old_value, new_value)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$patientId, $currentUserId, $field, $oldValue, $newValue]);

    jsonResponse(true, ['message' => 'Medical history update recorded.']);

} catch (Exception $e) {
    error_log('Add Medical History Update Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to record medical history update.']);
}

