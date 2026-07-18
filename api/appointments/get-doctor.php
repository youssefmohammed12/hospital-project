<?php
/**
 * HealthBridge — Get Doctor's Appointments
 * Returns appointments assigned to the logged-in doctor.
 * Notification fetching is now handled by the unified API (api/notifications.php).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';

$userId = requireRole('doctor')['id'];

try {
    $db = getDB();
    $wfService = new VisitWorkflowService($db);

    // Get doctor profile
    $doctorStmt = $db->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $doctorStmt->execute([$userId]);
    $doctor = $doctorStmt->fetch();

    if (!$doctor) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor profile not found.']);
    }

    // Get appointments for this doctor
    $apptStmt = $db->prepare(
        'SELECT a.id, a.user_id, a.patient_name, a.department, a.date, a.time, a.appointment_time_range, a.notes, a.status, a.created_at, a.doctor_id
         FROM appointments a WHERE a.doctor_id = ? ORDER BY a.date DESC, a.time DESC'
    );
    $apptStmt->execute([$doctor['id']]);
    $appointments = $apptStmt->fetchAll();

    // Get visit workflow for all appointments
    $apptIds = array_map(fn($a) => (int)$a['id'], $appointments);
    $workflowMap = !empty($apptIds) ? $wfService->getForAppointments($apptIds) : [];

    // Check for existing prescriptions and visit notes
    $rxCheckStmt = $db->prepare('SELECT id FROM prescriptions WHERE appointment_id = ? LIMIT 1');
    $vnCheckStmt = $db->prepare('SELECT id FROM visit_notes WHERE appointment_id = ? LIMIT 1');
    foreach ($appointments as &$appt) {
        $appt['has_prescription'] = false;
        $appt['prescription_id'] = null;
        $appt['has_visit_note'] = false;
        $appt['visit_workflow'] = $workflowMap[(int)$appt['id']] ?? null;
        
        if ($appt['status'] === 'Confirmed') {
            $rxCheckStmt->execute([(int)$appt['id']]);
            $existing = $rxCheckStmt->fetch();
            if ($existing) {
                $appt['has_prescription'] = true;
                $appt['prescription_id'] = (int)$existing['id'];
            }
            $vnCheckStmt->execute([(int)$appt['id']]);
            $vnExisting = $vnCheckStmt->fetch();
            if ($vnExisting) {
                $appt['has_visit_note'] = true;
            }
        }
        // Add appointment_time_range — prefer stored value
        if (empty($appt['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }
    }

    jsonResponse(true, [
        'doctor'        => $doctor,
        'appointments'  => $appointments,
        'total'         => count($appointments),
    ]);

} catch (Exception $e) {
    error_log('Get Doctor Appointments Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load appointments.']);
}
