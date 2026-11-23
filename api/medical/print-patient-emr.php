<?php
/**
 * HealthBridge — Standalone Printable EMR Page
 *
 * Opens in a new tab, auto-prints, and auto-closes if possible.
 * Shows a clean print-optimized view of all patient EMR data.
 *
 * Permissions:
 *   - Admin: can print any patient's EMR
 *   - Doctor: can print EMR of patients they have treated
 *   - Patient: can print only their own EMR
 *
 * Future: Can be used by DomPDF/TCPDF for PDF generation.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '<h1>405 Method Not Allowed</h1><p>This document is available with GET requests only.</p>';
    exit;
}

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if (!in_array($currentRole, ['admin', 'doctor', 'patient'], true)) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to view patient records.</p>';
    exit;
}

$patientId = parsePositiveInt($_GET['patient_id'] ?? null);

if ($currentRole === 'patient') {
    $patientId = $currentUserId;
} elseif ($patientId === null) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>A valid patient ID is required.</p>';
    exit;
}

try {
    $db = getDB();

    // Authorize doctors before checking patient existence. Otherwise the
    // different 403/404 responses reveal whether an arbitrary patient ID exists.
    if ($currentRole === 'doctor') {
        $clinicalAccess = new ClinicalAccessService($db);
        if (!$clinicalAccess->doctorCanAccessPatient($currentUserId, $patientId)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>You can only view records of patients with a confirmed appointment or documented clinical encounter.</p>';
            exit;
        }
    }

    // ── Fetch all data (same queries as get_emr_data.php) ──
    // Patient
    $stmt = $db->prepare("SELECT id, name, email, phone, is_active, created_at FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1><p>Patient not found.</p>';
        exit;
    }

    // Medical record
    $profile = (new PatientPortalService($db))->getMedicalProfile($patientId);
    $mr = [
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
    if (!$mr) {
        $mr = [
            'id' => null,'patient_id' => $patientId,'blood_type' => null,
            'height_cm' => null,'weight_kg' => null,'date_of_birth' => null,'gender' => null,
            'allergies' => null,'chronic_diseases' => null,'current_medications' => null,
            'previous_surgeries' => null,'family_history' => null,
            'emergency_contact_name' => null,'emergency_contact_rel' => null,
            'emergency_contact_phone' => null,'medical_notes' => null,
            'created_at' => null,'updated_at' => null,
        ];
    }

    // Age
    $age = null;
    if ($mr && !empty($mr['date_of_birth'])) {
        $age = (new DateTime($mr['date_of_birth']))->diff(new DateTime())->y;
    }

    // Appointments: map normalized V2 columns to the print template's stable DTO.
    $apptStmt = $db->prepare(
            "SELECT a.id,a.patient_user_id AS user_id,a.doctor_user_id AS doctor_id,
                    a.doctor_name_snapshot AS doctor,
                    a.department_name_snapshot AS department,
                    DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
                    TIME_FORMAT(a.starts_at,'%H:%i') AS time,
                    a.source_time_snapshot,a.source_time_range_snapshot,
                    a.duration_minutes,ce.workflow_status,
                    CASE
                      WHEN rr.request_status IN ('requested','suggested') THEN 'Reschedule Requested'
                      WHEN a.booking_status='pending' THEN 'Pending'
                      WHEN a.booking_status='confirmed' THEN 'Confirmed'
                      ELSE 'Cancelled'
                    END AS status
             FROM appointments a
             LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
             LEFT JOIN appointment_reschedule_requests rr
               ON rr.id=(
                 SELECT MAX(rr2.id) FROM appointment_reschedule_requests rr2
                 WHERE rr2.appointment_id=a.id
               )
             WHERE a.patient_user_id=?
             ORDER BY a.starts_at DESC
             LIMIT 20"
        );
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();
    foreach ($appointments as &$a) {
        $sourceTime = (string)($a['source_time_snapshot'] ?? '');
        if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$a['time'])) {
            $a['time'] = $sourceTime;
            $rangeSnapshot = $a['source_time_range_snapshot'] ?? null;
            $a['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                ? $rangeSnapshot
                : AppointmentTime::range((string)$a['time'], max(1, (int)($a['duration_minutes'] ?? 30)));
        } else {
            $a['appointment_time_range'] = AppointmentTime::range(
                (string)$a['time'],
                max(1, (int)($a['duration_minutes'] ?? 30))
            );
        }
    }
    unset($a);

    // Prescriptions and visit notes use the shared services.
    $prescriptionService = new PrescriptionService($db);
    $prescriptions = $prescriptionService->attachItems(
        $prescriptionService->getByPatient($patientId, 10),
        'item_count'
    );

    $visitNotes = array_slice(
        (new VisitWorkflowService($db))->getNotesForPatient($patientId),
        0,
        10
    );

    // Stats
    $totalAppts = count($appointments);
    $completedAppts = 0;
    $upcomingAppts = 0;
    $cancelledAppts = 0;
    foreach ($appointments as $a) {
        if ($a['status'] === 'Confirmed') {
            if (($a['workflow_status'] ?? null) === 'completed') $completedAppts++;
            elseif ($a['date'] >= date('Y-m-d')) $upcomingAppts++;
        } elseif ($a['status'] === 'Reschedule Requested') {
            if ($a['date'] >= date('Y-m-d')) $upcomingAppts++;
        } elseif ($a['status'] === 'Cancelled') {
            $cancelledAppts++;
        } elseif ($a['status'] === 'Pending') {
            $upcomingAppts++;
        }
    }

    $hospitalName = 'HealthBridge Medical Center';

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMR — <?= htmlspecialchars($patient['name']) ?> — HealthBridge</title>
    <link rel="stylesheet" href="../../assets/css/modules/print-prescription.css">
        <link rel="stylesheet" href="../../assets/css/print/medical-emr-print.css">
</head>
<body>
    <div class="print-container">
        <!-- Hospital Header -->
        <div class="header">
            <h1><?= htmlspecialchars($hospitalName) ?></h1>
            <p>Electronic Medical Record &mdash; Printed <?= date('F j, Y \a\t g:i A') ?></p>
        </div>

        <!-- Patient Header -->
        <div class="patient-info">
            <h2><?= htmlspecialchars($patient['name']) ?> <span class="emr-patient-id">#<?= $patient['id'] ?></span></h2>
            <div class="patient-grid">
                <div><span class="label">Status:</span> <?= $patient['is_active'] ? 'Active' : 'Disabled' ?></div>
                <div><span class="label">Gender:</span> <?= htmlspecialchars($mr['gender'] ?? '—') ?></div>
                <div><span class="label">Age:</span> <?= $age ?? '—' ?></div>
                <div><span class="label">Blood Type:</span> <?= htmlspecialchars($mr['blood_type'] ?? '—') ?></div>
                <div><span class="label">Email:</span> <?= htmlspecialchars($patient['email'] ?? '—') ?></div>
                <div><span class="label">Phone:</span> <?= htmlspecialchars($patient['phone'] ?? '—') ?></div>
                <div><span class="label">Registered:</span> <?= date('M j, Y', strtotime($patient['created_at'])) ?></div>
                <div><span class="label">Emergency:</span> <?= htmlspecialchars($mr['emergency_contact_name'] ?? '—') ?> <?= $mr['emergency_contact_phone'] ? '(' . htmlspecialchars($mr['emergency_contact_phone']) . ')' : '' ?></div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-row">
            <div class="stat-box"><div class="num"><?= $totalAppts ?></div><div class="lbl">Appointments</div></div>
            <div class="stat-box"><div class="num"><?= $upcomingAppts ?></div><div class="lbl">Upcoming</div></div>
            <div class="stat-box"><div class="num"><?= $completedAppts ?></div><div class="lbl">Completed</div></div>
            <div class="stat-box"><div class="num"><?= $cancelledAppts ?></div><div class="lbl">Cancelled</div></div>
            <div class="stat-box"><div class="num"><?= count($prescriptions) ?></div><div class="lbl">Prescriptions</div></div>
            <div class="stat-box"><div class="num"><?= count($visitNotes) ?></div><div class="lbl">Visit Notes</div></div>
        </div>

        <!-- Medical Information -->
        <?php if ($mr && ($mr['allergies'] || $mr['chronic_diseases'] || $mr['current_medications'] || $mr['previous_surgeries'] || $mr['family_history'] || $mr['medical_notes'])): ?>
        <section>
            <h3>Medical Information</h3>
            <?php if ($mr['allergies']): ?><div class="record-card"><strong>Allergies:</strong> <?= htmlspecialchars($mr['allergies']) ?></div><?php endif; ?>
            <?php if ($mr['chronic_diseases']): ?><div class="record-card"><strong>Chronic Diseases:</strong> <?= htmlspecialchars($mr['chronic_diseases']) ?></div><?php endif; ?>
            <?php if ($mr['current_medications']): ?><div class="record-card"><strong>Current Medications:</strong> <?= htmlspecialchars($mr['current_medications']) ?></div><?php endif; ?>
            <?php if ($mr['previous_surgeries']): ?><div class="record-card"><strong>Previous Surgeries:</strong> <?= htmlspecialchars($mr['previous_surgeries']) ?></div><?php endif; ?>
            <?php if ($mr['family_history']): ?><div class="record-card"><strong>Family History:</strong> <?= htmlspecialchars($mr['family_history']) ?></div><?php endif; ?>
            <?php if ($mr['medical_notes']): ?><div class="record-card"><strong>Medical Notes:</strong> <?= htmlspecialchars($mr['medical_notes']) ?></div><?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Appointments Summary -->
        <section>
            <h3>Appointments Summary (Last 20)</h3>
            <?php if ($appointments): ?>
            <table>
                <thead><tr><th>Date</th><th>Doctor</th><th>Department</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($appointments as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['date']) ?> <?= htmlspecialchars($a['appointment_time_range'] ?? '') ?></td>
                        <td><?= htmlspecialchars($a['doctor']) ?></td>
                        <td><?= htmlspecialchars($a['department']) ?></td>
                        <td><?= htmlspecialchars($a['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="no-data">No appointments recorded.</p>
            <?php endif; ?>
        </section>

        <!-- Prescriptions Summary -->
        <section>
            <h3>Prescriptions Summary</h3>
            <?php if ($prescriptions): foreach ($prescriptions as $rx): ?>
            <div class="record-card">
                <strong>RX-<?= str_pad($rx['id'], 5, '0', STR_PAD_LEFT) ?></strong> &mdash; Dr. <?= htmlspecialchars($rx['doctor_name']) ?>
                <span class="emr-prescription-status"><?= htmlspecialchars($rx['status']) ?></span>
                <p class="emr-prescription-meta"><?= date('M j, Y', strtotime($rx['created_at'])) ?> &middot; <?= $rx['item_count'] ?> medication(s)</p>
            </div>
            <?php endforeach; else: ?>
            <p class="no-data">No prescriptions recorded.</p>
            <?php endif; ?>
        </section>

        <!-- Visit Notes Summary -->
        <section>
            <h3>Visit Notes Summary</h3>
            <?php if ($visitNotes): foreach ($visitNotes as $vn): ?>
            <div class="record-card">
                <strong><?= date('M j, Y', strtotime($vn['appt_date'])) ?></strong> &mdash; Dr. <?= htmlspecialchars($vn['doctor_name']) ?>
                <?php if ($vn['diagnosis']): ?><p><strong>Diagnosis:</strong> <?= htmlspecialchars($vn['diagnosis']) ?></p><?php endif; ?>
                <?php if ($vn['treatment']): ?><p><strong>Treatment:</strong> <?= htmlspecialchars($vn['treatment']) ?></p><?php endif; ?>
                <?php if ($vn['follow_up_instructions']): ?><p><strong>Follow-up Instructions:</strong> <?= htmlspecialchars($vn['follow_up_instructions']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; else: ?>
            <p class="no-data">No visit notes recorded.</p>
            <?php endif; ?>
        </section>

        <!-- Footer -->
        <div class="footer">
            <p>HealthBridge Medical Center &mdash; Confidential Patient Record</p>
            <p>Generated on <?= date('F j, Y \a\t g:i A') ?> by <?= htmlspecialchars($user['name'] ?? 'Staff') ?> (<?= htmlspecialchars($currentRole) ?>)</p>
        </div>
    </div>

        <script src="../../assets/js/pages/print/medical-emr-print.js"></script>
</body>
</html>
<?php
} catch (Throwable $e) {
    error_log('Print EMR Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Server Error</h1><p>Failed to generate EMR print page.</p>';
}

