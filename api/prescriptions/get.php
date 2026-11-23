<?php
/**
 * HealthBridge — Get Prescriptions
 * Returns prescriptions based on user role.
 *
 * Permissions:
 *   - Patient: view only their own prescriptions
 *   - Doctor: view any single prescription in full (clinical continuity —
 *     a treating doctor must see what medication the patient is taking,
 *     whoever prescribed it). Editing stays author-only and is enforced
 *     by the update/complete/cancel endpoints, not here.
 *   - Admin: view all prescriptions (audit only)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

header('Content-Type: application/json');

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if (!in_array($currentRole, ['admin', 'doctor', 'patient'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'You do not have permission to view prescriptions.']);
}

// Optional single-record reads must not silently fall through to a list request.
$prescriptionId = 0;
if (array_key_exists('id', $_GET)) {
    $idValue = $_GET['id'];
    $normalizedId = is_string($idValue) ? ltrim($idValue, '0') : '';
    $parsedId = is_string($idValue) && ctype_digit($idValue) ? (int)$idValue : 0;

    if ($parsedId < 1 || (string)$parsedId !== $normalizedId) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Prescription ID must be a positive integer.']);
    }

    $prescriptionId = $parsedId;
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

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

        // Doctors may view any prescription: medication visibility across
        // prescribers is safety-critical for the treating doctor. Write
        // access remains restricted to the creator by the write endpoints.

        jsonResponse(true, ['prescription' => $prescription]);
    }

    // List prescriptions based on role with validated optional filters.
    $filters = [];

    $status = $_GET['status'] ?? null;
    if ($status !== null && $status !== '') {
        $allowedStatuses = ['Active', 'Completed', 'Cancelled'];
        if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Invalid prescription status filter.']);
        }
        $filters['status'] = $status;
    }

    $search = $_GET['search'] ?? null;
    if ($search !== null && $search !== '') {
        if (!is_string($search) || mb_strlen(trim($search)) > 100) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Prescription search must be text no longer than 100 characters.']);
        }
        $search = trim($search);
        if ($search !== '') {
            $filters['search'] = $search;
        }
    }

    foreach (['date_from', 'date_to'] as $dateField) {
        $dateValue = $_GET[$dateField] ?? null;
        if ($dateValue === null || $dateValue === '') {
            continue;
        }

        if (
            !is_string($dateValue)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)
        ) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Prescription date filters must use YYYY-MM-DD format.']);
        }

        [$year, $month, $day] = array_map('intval', explode('-', $dateValue));
        if (!checkdate($month, $day, $year)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Prescription date filters must contain a valid calendar date.']);
        }

        $filters[$dateField] = $dateValue;
    }

    if (
        isset($filters['date_from'], $filters['date_to'])
        && $filters['date_from'] > $filters['date_to']
    ) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'The start date cannot be later than the end date.']);
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

} catch (Throwable $e) {
    error_log('Get Prescriptions Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load prescriptions.']);
}
