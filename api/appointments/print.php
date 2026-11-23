<?php
/**
 * HealthBridge — Standalone Printable Appointment Documents
 *
 * One endpoint, two genuinely different documents:
 *   - Appointment Confirmation (default): the booking record — who, with
 *     whom, where, when, status, booking details.
 *   - Visit Summary (?type=summary): the clinical record of the visit —
 *     visit context, the doctor's note (symptoms, diagnosis, treatment,
 *     notes, follow-up) and the prescriptions issued at the visit with
 *     their full medication items.
 *
 * Both render in the Public V2 print design (the same language as the
 * prescription print). Opens in a new tab, auto-prints, and auto-closes
 * if possible. GET only; responses are private/no-store and row-scoped
 * to the current patient or doctor.
 *
 * Permissions: Admin, Doctor (if their appointment), Patient (if their appointment)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/PrintOutputContext.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/HospitalSettingsService.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentBookingRepository.php';
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '<h1>405 Method Not Allowed</h1>';
    exit;
}

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

$appointmentIdValue = $_GET['id'] ?? null;
$appointmentId = is_string($appointmentIdValue) && ctype_digit($appointmentIdValue)
    ? (int)$appointmentIdValue
    : 0;

if ($appointmentId <= 0) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>Appointment ID is required.</p>';
    exit;
}

// The Document Center links the Visit Summary with ?type=summary; every
// other value (or none) is the Appointment Confirmation.
$docType = (($_GET['type'] ?? '') === 'summary') ? 'summary' : 'confirmation';

try {
    $db = getDB();

    $appointmentSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_APPOINTMENTS_SCHEMA');
    $clinicalSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_CLINICAL_SCHEMA');

    // Project the V2 snapshot/instant model into the established print DTO.
    if ($appointmentSchema === AppointmentBookingRepository::SCHEMA_V2) {
        $stmt = $db->prepare(
            "SELECT a.id, a.patient_user_id AS user_id, a.doctor_user_id AS doctor_id,
                    a.patient_name_snapshot AS patient_name,
                    a.doctor_name_snapshot AS doctor,
                    a.department_name_snapshot AS department,
                    DATE(a.starts_at) AS date,
                    TIME_FORMAT(a.starts_at, '%H:%i') AS time,
                    CONCAT(TIME_FORMAT(a.starts_at, '%H:%i'), ' - ',
                           TIME_FORMAT(DATE_ADD(a.starts_at, INTERVAL a.duration_minutes MINUTE), '%H:%i')) AS appointment_time_range,
                    a.notes,
                    CASE a.booking_status
                        WHEN 'pending' THEN 'Pending'
                        WHEN 'confirmed' THEN 'Confirmed'
                        WHEN 'cancelled' THEN 'Cancelled'
                        WHEN 'completed' THEN 'Completed'
                        ELSE a.booking_status
                    END AS status,
                    a.created_at,
                    u.name AS patient_user_name,
                    u2.name AS doctor_user_name
             FROM appointments a
             LEFT JOIN users u ON a.patient_user_id = u.id
             LEFT JOIN users u2 ON a.doctor_user_id = u2.id
             WHERE a.id = ? LIMIT 1"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT a.*, u.name as patient_user_name, u2.name as doctor_user_name
             FROM appointments a
             LEFT JOIN users u ON a.user_id = u.id
             LEFT JOIN users u2 ON a.doctor_id = u2.id
             WHERE a.id = ? LIMIT 1"
        );
    }
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1><p>Appointment not found.</p>';
        exit;
    }

    // Permission check — identical for both documents.
    if ($currentRole === 'patient' && (int)$appt['user_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You can only view your own appointments.</p>';
        exit;
    }
    if ($currentRole === 'doctor' && (int)$appt['doctor_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You can only view your own appointments.</p>';
        exit;
    }

    // Get time range
    if (empty($appt['appointment_time_range'])) {
        $duration = getAppointmentDuration((int)$appt['doctor_id']);
        $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
    }

    // ── Shared, formatted values ──
    $esc = static fn($value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    $patientName = $appt['patient_name'] ?: ($appt['patient_user_name'] ?? '—');
    $doctorName  = $appt['doctor'] ?: ($appt['doctor_user_name'] ?? '—');
    $department  = $appt['department'] ?? '—';
    $status      = (string)($appt['status'] ?? '');
    $statusToken = in_array($status, ['Pending', 'Confirmed', 'Cancelled', 'Completed'], true) ? $status : 'unknown';
    $dateTs      = !empty($appt['date']) ? strtotime((string)$appt['date']) : false;
    $dateLong    = $dateTs ? date('F j, Y', $dateTs) : (string)($appt['date'] ?? '—');
    $timeRange   = $appt['appointment_time_range'] ?? $appt['time'] ?? '—';
    $printedNow  = date('F j, Y \a\t g:i A');
    $apptRef     = 'Appointment #' . $appointmentId;

    // Hospital identity — same source as the prescription print.
    $hospitalSettings = (new HospitalSettingsService(
        $db,
        HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_HOSPITAL_SCHEMA')
    ))->getSettings();
    $hospitalName = trim((string)($hospitalSettings['hospital_name'] ?? '')) ?: 'HealthBridge Hospital';
    $hospitalAddress = trim((string)($hospitalSettings['hospital_address'] ?? ''));
    $hospitalPhone = trim((string)($hospitalSettings['hospital_phone'] ?? ''));
    $hospitalEmail = trim((string)($hospitalSettings['hospital_email'] ?? ''));
    $hospitalContactParts = array_filter([
        $hospitalAddress,
        $hospitalPhone !== '' ? 'Tel: ' . $hospitalPhone : '',
        $hospitalEmail !== '' ? 'Email: ' . $hospitalEmail : '',
    ], static fn(string $value): bool => $value !== '');
    $hospitalContactLine = implode(' · ', array_map(
        static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
        $hospitalContactParts
    ));

    // Determine dashboard URL for return button
    $dashboardUrl = '';
    if ($currentRole === 'patient') $dashboardUrl = '../../pages/patient/downloads.html';
    elseif ($currentRole === 'doctor') $dashboardUrl = '../../pages/doctor/appointments.html';
    elseif ($currentRole === 'admin') $dashboardUrl = '../../pages/admin/admin.html';

    $renderHeader = static function () use ($esc, $hospitalName, $hospitalContactLine): void {
?>
    <!-- Hospital Header -->
    <div class="print-header">
        <div class="print-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="#a9844f" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V8l8-5 8 5v13"/><path d="M2 21h20"/><path d="M12 8v4M10 10h4"/><path d="M8 21v-4h8v4"/></svg>
        </div>
        <div class="print-logo-text">
            <h1><?php echo $esc($hospitalName); ?></h1>
            <p><?php echo $hospitalContactLine !== '' ? $hospitalContactLine : 'Hospital contact details are not configured.'; ?></p>
        </div>
    </div>
<?php
    };

    $renderReturn = static function () use ($esc, $dashboardUrl): void {
?>
    <!-- Return Button (shown if auto-close fails) -->
    <div class="print-return" id="print-return">
        <a href="<?php echo $esc($dashboardUrl); ?>">← Return to your workspace</a>
        <p>If the window did not close automatically, click the button above.</p>
    </div>
<?php
    };

    if ($docType === 'confirmation') {
        $bookedTs = !empty($appt['created_at']) ? strtotime((string)$appt['created_at']) : false;
        $bookedOn = $bookedTs ? date('F j, Y', $bookedTs) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Confirmation #<?= $appointmentId ?> — HealthBridge</title>
    <link rel="stylesheet" href="../../assets/public-v2/css/pages/print-appointment.css?v=1">
</head>
<body>

    <?php $renderHeader(); ?>

    <!-- Meta Row -->
    <div class="print-meta">
        <div>
            <h2 class="ap-title">Appointment Confirmation</h2>
            <span class="ap-ref"><?php echo $esc($apptRef); ?></span>
            <div class="print-date">Printed: <?php echo $esc($printedNow); ?></div>
            <?php if ($bookedOn): ?>
                <div class="print-updated">Booked: <?php echo $esc($bookedOn); ?></div>
            <?php endif; ?>
        </div>
        <div>
            <span class="print-status print-status-<?php echo $esc($statusToken); ?>"><?php echo $esc($status); ?></span>
        </div>
    </div>

    <!-- Booking Details -->
    <div class="print-info-grid">
        <div class="print-info-box">
            <h3>Patient</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo $esc($patientName); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3>Doctor</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo $esc($doctorName); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Department</span>
                <span class="print-info-value"><?php echo $esc($department); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3>Appointment</h3>
            <div class="print-info-row">
                <span class="print-info-label">Date</span>
                <span class="print-info-value"><?php echo $esc($dateLong); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Time</span>
                <span class="print-info-value"><?php echo $esc($timeRange); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Status</span>
                <span class="print-info-value"><?php echo $esc($status); ?></span>
            </div>
        </div>
    </div>

    <?php if (!empty($appt['notes'])): ?>
    <!-- Booking Notes -->
    <div class="print-notes">
        <h3>Booking Notes</h3>
        <p><?php echo $esc($appt['notes']); ?></p>
    </div>
    <?php endif; ?>

    <!-- Signature -->
    <div class="print-signature">
        <div class="print-signature-left">
            <h3>Attending Doctor</h3>
            <div class="print-signature-name"><?php echo $esc($doctorName); ?></div>
            <div class="print-signature-details"><?php echo $esc($department); ?></div>
            <div class="print-signature-line">Doctor booked for this appointment</div>
        </div>
        <div class="print-signature-right">
            <span>Document reference</span><br>
            <strong><?php echo $esc($apptRef); ?></strong>
        </div>
    </div>

    <p class="ap-confidential">HealthBridge — Confidential Patient Record</p>

    <?php $renderReturn(); ?>

        <script src="../../assets/public-v2/js/pages/prescription-print.js?v=1"></script>
</body>
</html>
<?php
    } else {
        // ── Visit Summary ──
        // The clinical record: the visit note (null unless at least one
        // clinical field was recorded) plus every prescription issued at
        // this visit, fetched in full — items included — through the same
        // service the prescription print uses.
        $visitNote = (new VisitWorkflowService($db, $clinicalSchema))->getNote($appointmentId);

        $prescriptionService = new PrescriptionService(
            $db,
            HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PRESCRIPTIONS_SCHEMA')
        );
        $rxIdStmt = $db->prepare(
            "SELECT id FROM prescriptions WHERE appointment_id = ? ORDER BY id ASC"
        );
        $rxIdStmt->execute([$appointmentId]);
        $visitPrescriptions = [];
        foreach ($rxIdStmt->fetchAll(PDO::FETCH_COLUMN) as $rxId) {
            $rxFull = $prescriptionService->get((int)$rxId);
            if ($rxFull) {
                $visitPrescriptions[] = $rxFull;
            }
        }

        $followUp = $visitNote['follow_up_instructions'] ?? ($visitNote['follow_up'] ?? null);

        $clinicalItems = [];
        if ($visitNote) {
            if (!empty($visitNote['symptoms']))    $clinicalItems[] = ['Chief Complaint & Symptoms', $visitNote['symptoms']];
            if (!empty($visitNote['diagnosis']))   $clinicalItems[] = ['Diagnosis', $visitNote['diagnosis']];
            if (!empty($visitNote['treatment']))   $clinicalItems[] = ['Treatment Plan', $visitNote['treatment']];
            if (!empty($visitNote['doctor_notes'])) $clinicalItems[] = ["Doctor's Notes", $visitNote['doctor_notes']];
            if (!empty($followUp))                 $clinicalItems[] = ['Follow-Up Instructions', $followUp];
        }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Summary #<?= $appointmentId ?> — HealthBridge</title>
    <link rel="stylesheet" href="../../assets/public-v2/css/pages/print-appointment.css?v=1">
</head>
<body>

    <?php $renderHeader(); ?>

    <!-- Meta Row -->
    <div class="print-meta">
        <div>
            <h2 class="ap-title">Visit Summary</h2>
            <span class="ap-ref"><?php echo $esc($apptRef); ?></span>
            <div class="print-date">Visit: <?php echo $esc($dateLong); ?> · <?php echo $esc($timeRange); ?></div>
            <div class="print-updated">Printed: <?php echo $esc($printedNow); ?></div>
        </div>
        <div>
            <span class="print-status print-status-<?php echo $esc($statusToken); ?>"><?php echo $esc($status); ?></span>
        </div>
    </div>

    <!-- Visit Context -->
    <div class="print-info-grid">
        <div class="print-info-box">
            <h3>Patient</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo $esc($patientName); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3>Doctor</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo $esc($doctorName); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Department</span>
                <span class="print-info-value"><?php echo $esc($department); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3>Visit</h3>
            <div class="print-info-row">
                <span class="print-info-label">Date</span>
                <span class="print-info-value"><?php echo $esc($dateLong); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Time</span>
                <span class="print-info-value"><?php echo $esc($timeRange); ?></span>
            </div>
        </div>
    </div>

    <?php if (!$visitNote && empty($visitPrescriptions)): ?>
    <!-- No clinical record exists for this appointment yet -->
    <div class="ap-empty">
        <h2>No visit summary available yet</h2>
        <p><?php if ($status === 'Completed'): ?>This visit is marked completed, but the doctor has not recorded a visit note for it yet, so there is no clinical summary to show.<?php else: ?>A visit summary is created once the doctor records the note for this visit. This appointment has not been completed yet, so no summary exists for it.<?php endif; ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($clinicalItems)): ?>
    <!-- Clinical Record -->
    <section class="ap-section">
        <h3 class="ap-section-title">Clinical Record</h3>
        <?php foreach ($clinicalItems as [$itemLabel, $itemText]): ?>
        <div class="ap-item">
            <h4><?php echo $esc($itemLabel); ?></h4>
            <p><?php echo $esc($itemText); ?></p>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php foreach ($visitPrescriptions as $visitRx): ?>
    <?php
        $rxItems = $visitRx['items'] ?? [];
        $rxRef = 'RX-' . str_pad((string)$visitRx['id'], 5, '0', STR_PAD_LEFT);
    ?>
    <!-- Prescription issued at this visit -->
    <div class="print-meds-section">
        <div class="print-meds-title">
            Prescription <?php echo $esc($rxRef); ?>
            <span class="print-meds-count"> (<?php echo count($rxItems); ?> item<?php echo count($rxItems) !== 1 ? 's' : ''; ?> · <?php echo $esc($visitRx['status'] ?? ''); ?>)</span>
        </div>

        <?php if (empty($rxItems)): ?>
            <p class="print-empty-message">No medication items found.</p>
        <?php else: ?>
            <div class="print-meds-table-wrapper">
            <table class="print-meds-table">
                <thead>
                    <tr>
                        <th class="print-meds-col print-meds-col--number">#</th>
                        <th class="print-meds-col print-meds-col--medication">Medication</th>
                        <th class="print-meds-col print-meds-col--strength">Strength</th>
                        <th class="print-meds-col print-meds-col--dosage">Dosage</th>
                        <th class="print-meds-col print-meds-col--frequency">Frequency</th>
                        <th class="print-meds-col print-meds-col--duration">Duration</th>
                        <th class="print-meds-col print-meds-col--instructions">Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rxItems as $i => $item): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td class="med-name"><?php echo $esc($item['medication_name']); ?></td>
                        <td><?php echo $esc($item['strength']); ?></td>
                        <td><?php echo $esc($item['dosage']); ?></td>
                        <td><?php echo $esc($item['frequency']); ?></td>
                        <td><?php echo $esc($item['duration']); ?></td>
                        <td><?php echo $esc($item['instructions'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($visitRx['notes'])): ?>
    <div class="print-notes">
        <h3>Prescription Notes</h3>
        <p><?php echo $esc($visitRx['notes']); ?></p>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <!-- Signature -->
    <div class="print-signature">
        <div class="print-signature-left">
            <h3>Attending Doctor</h3>
            <div class="print-signature-name"><?php echo $esc($doctorName); ?></div>
            <div class="print-signature-details"><?php echo $esc($department); ?></div>
            <div class="print-signature-line">Attending doctor for this visit</div>
        </div>
        <div class="print-signature-right">
            <span>Document reference</span><br>
            <strong><?php echo $esc($apptRef); ?></strong>
        </div>
    </div>

    <p class="ap-confidential">HealthBridge — Confidential Patient Record</p>

    <?php $renderReturn(); ?>

        <script src="../../assets/public-v2/js/pages/prescription-print.js?v=1"></script>
</body>
</html>
<?php
    }
} catch (Exception $e) {
    error_log('Print Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Server Error</h1><p>Failed to generate appointment print page.</p>';
}
