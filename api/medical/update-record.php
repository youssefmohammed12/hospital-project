<?php
/**
 * HealthBridge — Update Medical Record
 *
 * Doctors may update patients they have treated; admins may update any patient.
 * Patient and doctor relationship is checked by the schema-aware repository.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole = (string)$user['role'];

if (!in_array($currentRole, ['doctor', 'admin'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors and admins can update medical records.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$patientId = parsePositiveInt($input['patient_id'] ?? null);
if ($patientId === null || $patientId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

$reasonValue = $input['reason'] ?? '';
if (!is_string($reasonValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Administrative correction reason must be text.']);
}
$reason = trim($reasonValue);
if (mb_strlen($reason) > 1000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Administrative correction reason must not exceed 1000 characters.']);
}

$allowedFields = [
    'blood_type', 'height_cm', 'weight_kg', 'date_of_birth', 'gender',
    'allergies', 'chronic_diseases', 'current_medications',
    'previous_surgeries', 'family_history',
    'emergency_contact_name', 'emergency_contact_rel', 'emergency_contact_phone',
    'medical_notes',
];
$changes = [];
foreach ($allowedFields as $field) {
    if (!array_key_exists($field, $input)) {
        continue;
    }

    $value = $input[$field];
    if ($value === null || $value === '') {
        $changes[$field] = null;
        continue;
    }

    if (in_array($field, ['height_cm', 'weight_kg'], true)) {
        if (!(is_int($value) || is_float($value) || is_string($value)) || !is_numeric($value)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Height and weight values must be numeric.']);
        }
        $changes[$field] = (float)$value;
        continue;
    }

    if (!is_string($value)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Medical record text fields must be text.']);
    }
    if (mb_strlen($value) > 10000) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Medical record text fields must not exceed 10000 characters.']);
    }
    if ($field === 'date_of_birth' && validateDate($value, 'Date of birth') !== null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Date of birth must be a valid YYYY-MM-DD date.']);
    }
    if ($field === 'gender' && !in_array($value, ['Male', 'Female', 'Other'], true)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Gender must be Male, Female, or Other.']);
    }
    $changes[$field] = $value;
}

try {
    $db = getDB();

    // The live application is still V1. The repository's V2 branch is tested
    // against disposable fixtures and can be selected only during approved cutover.
    $management = new PatientManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PATIENTS_SCHEMA'));
    $result = $management->updateMedicalProfile(
        $patientId,
        $changes,
        $currentUserId,
        $currentRole,
        $reason
    );

    if (!$result['changed']) {
        jsonResponse(true, ['message' => 'No changes detected.']);
    }

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $dispatchError) {
        error_log('Medical record update committed; notification dispatch deferred: ' . get_class($dispatchError));
    }

    jsonResponse(true, ['message' => 'Medical record updated successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'not found')) {
        http_response_code(404);
    } elseif (str_contains($message, 'Reason is required')) {
        http_response_code(400);
    } else {
        http_response_code(403);
    }
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Update Medical Record Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update medical record. Please try again.']);
}
