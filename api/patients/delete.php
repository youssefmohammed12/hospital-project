<?php
/**
 * HealthBridge — Admin: Archive Patient
 *
 * Disables sign-in and cancels only appointments whose clinical work has not
 * started. Patient, EMR, notes, prescriptions and audit history are retained.
 * POST { id }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$id = parsePositiveInt($data['id'] ?? null);
if ($id <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid patient ID.']);
}

try {
    $db = getDB();

    // Keep current runtime on v1; v2 behavior is exercised through disposable fixtures.
    $management = new PatientManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PATIENTS_SCHEMA'));
    $result = $management->archivePatient($id, (int)$user['id']);

    jsonResponse(true, [
        'message' => "Patient '{$result['patient_name']}' archived. Sign-in is disabled; medical and audit history has been retained.",
        'cancelled_appointments' => (int)$result['cancelled_appointments'],
        'is_active' => 0,
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Patient not found.') {
        http_response_code(404);
    } elseif ($message === 'This patient account is already disabled.') {
        http_response_code(409);
    } else {
        http_response_code(403);
        $message = 'An active administrator is required to archive a patient account.';
    }
    jsonResponse(false, ['message' => $message]);
} catch (Throwable $e) {
    error_log('Archive Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to archive patient. Please try again.']);
}
