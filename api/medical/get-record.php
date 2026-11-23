<?php
/**
 * HealthBridge — Get Medical Record
 * Returns a patient's medical record and visit history.
 *
 * Permissions:
 *   - Patient: can view their own record only
 *   - Doctor: can view records of patients they have treated
 *   - Admin: can view any patient's record
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

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
    jsonResponse(false, ['message' => 'You do not have permission to view patient records.']);
}

// Determine which patient's record to fetch. Patient callers are always
// scoped to their authenticated account; staff must supply a strict ID.
$patientId = parsePositiveInt($_GET['patient_id'] ?? null);

if ($currentRole === 'patient') {
    $patientId = $currentUserId;
} elseif ($patientId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

try {
    $db = getDB();
    $workflowService = new VisitWorkflowService($db);

    // ── Authorization Checks ──
    if ($currentRole === 'doctor') {
        $clinicalAccess = new ClinicalAccessService($db);
        if (!$clinicalAccess->doctorCanAccessPatient($currentUserId, $patientId)) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view records of patients with a confirmed appointment or documented clinical encounter.']);
        }
    }

    // ── Get patient info ──
    $patientStmt = $db->prepare(
        "SELECT id, name, email, phone, created_at FROM users WHERE id = ? AND role = 'patient' LIMIT 1"
    );
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch();

    if (!$patient) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    // ── Get medical record ──
    // Reuse the portal's normalized patient-profile mapper for the split V2 tables.
    $profile = (new PatientPortalService($db))->getMedicalProfile($patientId);
    $medicalRecord = [
            'id' => null,
            'patient_id' => $patientId,
            'blood_type' => $profile['blood_type'] ?? null,
            'height_cm' => $profile['height_cm'] ?? null,
            'weight_kg' => $profile['weight_kg'] ?? null,
            'date_of_birth' => $profile['date_of_birth'] ?? null,
            'gender' => $profile['gender'] ?? null,
            'allergies' => $profile['allergies'] ?? null,
            'chronic_diseases' => $profile['chronic_diseases'] ?? null,
            'current_medications' => $profile['current_medications'] ?? null,
            'previous_surgeries' => $profile['previous_surgeries'] ?? null,
            'family_history' => $profile['family_history'] ?? null,
            'emergency_contact_name' => $profile['emergency_contact']['name'] ?? null,
            'emergency_contact_rel' => $profile['emergency_contact']['relationship'] ?? null,
            'emergency_contact_phone' => $profile['emergency_contact']['phone'] ?? null,
            'medical_notes' => $profile['medical_notes'] ?? null,
            'created_at' => null,
            'updated_at' => null,
        ];

    // Keep GET read-only; return an empty record model until an explicit save creates it.
    if (!$medicalRecord) {
        $medicalRecord = [
            'id' => null,
            'patient_id' => $patientId,
            'blood_type' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'date_of_birth' => null,
            'gender' => null,
            'allergies' => null,
            'chronic_diseases' => null,
            'current_medications' => null,
            'previous_surgeries' => null,
            'family_history' => null,
            'emergency_contact_name' => null,
            'emergency_contact_rel' => null,
            'emergency_contact_phone' => null,
            'medical_notes' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    // ── Get visit history through the normalized clinical repository ──
    $visitHistory = $workflowService->getNotesForPatient($patientId);
    
    // Add appointment_time_range to visit history — prefer stored value
    foreach ($visitHistory as &$visit) {
        if (empty($visit['appointment_time_range'])) {
            $sourceTime = (string)($visit['source_time_snapshot'] ?? '');
            if (
                $sourceTime !== ''
                && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$visit['appt_time'])
            ) {
                $visit['appointment_time_range'] = $visit['source_time_range_snapshot'] ?? null;
            } else {
                $visit['appointment_time_range'] = AppointmentTime::range(
                    (string)$visit['appt_time'],
                    max(1, (int)($visit['duration_minutes'] ?? 30))
                );
            }
        }
    }
    unset($visit);

    // ── Get confirmed appointments that do not yet have a clinical note ──
    $doctorFilter = $currentRole === 'doctor' ? ' AND a.doctor_user_id=?' : '';
    $sql = "SELECT a.id,
                       DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
                       DATE_FORMAT(a.starts_at,'%H:%i') AS time,
                       a.department_name_snapshot AS department,
                       a.doctor_name_snapshot AS doctor,
                       'Confirmed' AS status,
                       a.doctor_user_id AS doctor_id,
                       a.duration_minutes,a.source_time_snapshot,a.source_time_range_snapshot
                FROM appointments a
                WHERE a.patient_user_id=? AND a.booking_status='confirmed'
                  {$doctorFilter}
                  AND NOT EXISTS (
                    SELECT 1 FROM appointment_reschedule_requests rr
                    WHERE rr.appointment_id=a.id
                      AND rr.request_status IN ('requested','suggested')
                  )
                ORDER BY a.starts_at DESC";
    $pendingNotesStmt = $db->prepare($sql);
    $params = [$patientId];
    if ($currentRole === 'doctor') {
        $params[] = $currentUserId;
    }
    $pendingNotesStmt->execute($params);
    $pendingForNotes = $pendingNotesStmt->fetchAll();
    $noteAppointmentIds = array_fill_keys(
        array_map(static fn(array $note): int => (int)$note['appointment_id'], $visitHistory),
        true
    );
    $pendingForNotes = array_values(array_filter(
        $pendingForNotes,
        static fn(array $appointment): bool => !isset($noteAppointmentIds[(int)$appointment['id']])
    ));
    
    // Add appointment_time_range to pending for notes — prefer stored value
    foreach ($pendingForNotes as &$pfn) {
        if (empty($pfn['appointment_time_range'])) {
            $sourceTime = (string)($pfn['source_time_snapshot'] ?? '');
            if (
                $sourceTime !== ''
                && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$pfn['time'])
            ) {
                $pfn['appointment_time_range'] = $pfn['source_time_range_snapshot'] ?? null;
            } else {
                $pfn['appointment_time_range'] = AppointmentTime::range(
                    (string)$pfn['time'],
                    max(1, (int)($pfn['duration_minutes'] ?? 30))
                );
            }
        }
    }
    unset($pfn);

    jsonResponse(true, [
        'patient'         => $patient,
        'medical_record'  => $medicalRecord,
        'visit_history'   => $visitHistory,
        'pending_for_notes' => $pendingForNotes,
        'can_edit'        => in_array($currentRole, ['doctor', 'admin']),
    ]);

} catch (Throwable $e) {
    error_log('Get Medical Record Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load medical record.']);
}
