<?php
/**
 * HealthBridge — Standalone Printable Appointment Page
 *
 * Opens in a new tab, auto-prints, and auto-closes if possible.
 * GET only; responses are private/no-store and row-scoped to the current patient or doctor.
 *
 * Permissions: Admin, Doctor (if their appointment), Patient (if their appointment)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/PrintOutputContext.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
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

    $appointmentStatusClass = PrintOutputContext::appointmentStatusClass($appt['status'] ?? null);

    // Permission check
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

    // Get visit note through the shared clinical repository.
    $visitNote = (new VisitWorkflowService($db, $clinicalSchema))->getNote($appointmentId);

    // Get prescription
    $rxStmt = $db->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = p.id) as item_count
         FROM prescriptions p WHERE p.appointment_id = ? LIMIT 1"
    );
    $rxStmt->execute([$appointmentId]);
    $rx = $rxStmt->fetch();

    $hospitalName = 'HealthBridge Medical Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment #<?= $appointmentId ?> — HealthBridge</title>
    <link rel="stylesheet" href="../../assets/css/modules/print-prescription.css">
        <link rel="stylesheet" href="../../assets/css/print/appointment-print.css">
</head>
<body>
    <div class="print-container">
        <div class="header">
            <h1><?= htmlspecialchars($hospitalName) ?></h1>
            <p>Appointment Record &mdash; Printed <?= date('F j, Y \a\t g:i A') ?></p>
        </div>

        <div class="info-card">
            <h2>Appointment #<?= $appointmentId ?></h2>
            <div class="info-grid">
                <div><span class="label">Patient:</span> <?= htmlspecialchars($appt['patient_name'] ?: ($appt['patient_user_name'] ?? '—')) ?></div>
                <div><span class="label">Status:</span> <span class="status-badge <?= htmlspecialchars($appointmentStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($appt['status']) ?></span></div>
                <div><span class="label">Doctor:</span> <?= htmlspecialchars($appt['doctor'] ?: ($appt['doctor_user_name'] ?? '—')) ?></div>
                <div><span class="label">Department:</span> <?= htmlspecialchars($appt['department']) ?></div>
                <div><span class="label">Date:</span> <?= htmlspecialchars($appt['date']) ?></div>
                <div><span class="label">Time:</span> <?= htmlspecialchars($appt['appointment_time_range'] ?? $appt['time']) ?></div>
                <?php if ($appt['notes']): ?>
                <div class="appointment-notes"><span class="label">Notes:</span> <?= htmlspecialchars($appt['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($visitNote): ?>
        <section>
            <h3>Visit Note</h3>
            <div class="record-card">
                <?php if ($visitNote['diagnosis']): ?><p><strong>Diagnosis:</strong> <?= htmlspecialchars($visitNote['diagnosis']) ?></p><?php endif; ?>
                <?php if ($visitNote['symptoms']): ?><p><strong>Symptoms:</strong> <?= htmlspecialchars($visitNote['symptoms']) ?></p><?php endif; ?>
                <?php if ($visitNote['treatment']): ?><p><strong>Treatment:</strong> <?= htmlspecialchars($visitNote['treatment']) ?></p><?php endif; ?>
                <?php if ($visitNote['doctor_notes']): ?><p><strong>Doctor Notes:</strong> <?= htmlspecialchars($visitNote['doctor_notes']) ?></p><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($rx): ?>
        <section>
            <h3>Prescription</h3>
            <div class="record-card">
                <p><strong>RX ID:</strong> RX-<?= str_pad($rx['id'], 5, '0', STR_PAD_LEFT) ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($rx['status']) ?></p>
                <p><strong>Medications:</strong> <?= $rx['item_count'] ?></p>
            </div>
        </section>
        <?php endif; ?>

        <div class="footer">
            <p>HealthBridge Medical Center &mdash; Confidential Patient Record</p>
            <p>Generated on <?= date('F j, Y \a\t g:i A') ?> by <?= htmlspecialchars($user['name'] ?? 'Staff') ?> (<?= htmlspecialchars($currentRole) ?>)</p>
        </div>
    </div>

        <script src="../../assets/js/pages/print/appointment-print.js"></script>
</body>
</html>
<?php
} catch (Exception $e) {
    error_log('Print Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Server Error</h1><p>Failed to generate appointment print page.</p>';
}

