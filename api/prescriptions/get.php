<?php
/**
 * HealthBridge — Get Prescriptions
 * Returns prescriptions based on user role.
 *
 * Permissions:
 *   - Patient: view only their own prescriptions
 *   - Doctor: view only prescriptions they created
 *   - Admin: view all prescriptions (audit only)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    // Optional: get single prescription details
    $prescriptionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($prescriptionId > 0) {
        // Get single prescription with items
        $prescription = $ps->get($prescriptionId);

        if (!$prescription) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Prescription not found.']);
        }

        // Permission check for single prescription
        if ($currentRole === 'patient' && (int)$prescription['patient_id'] !== $currentUserId) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view your own prescriptions.']);
        }

        if ($currentRole === 'doctor' && (int)$prescription['doctor_id'] !== $currentUserId) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view prescriptions you created.']);
        }

        jsonResponse(true, ['prescription' => $prescription]);
    }

    // List prescriptions based on role with optional search/filter
    $filters = [];

    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];
    }
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }
    if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
        $filters['date_from'] = $_GET['date_from'];
    }
    if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
        $filters['date_to'] = $_GET['date_to'];
    }

    $prescriptions = [];

    if ($currentRole === 'patient') {
        $filters['patient_id'] = $currentUserId;
        $prescriptions = $ps->search($filters);
    } elseif ($currentRole === 'doctor') {
        $filters['doctor_id'] = $currentUserId;
        $prescriptions = $ps->search($filters);
    } elseif ($currentRole === 'admin') {
        $prescriptions = $ps->search($filters);
    }

    jsonResponse(true, ['prescriptions' => $prescriptions]);

} catch (Exception $e) {
    error_log('Get Prescriptions Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load prescriptions.']);
}
