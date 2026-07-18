<?php
/**
 * HealthBridge — Standalone Printable Appointment Page
 *
 * Opens in a new tab, auto-prints, and auto-closes if possible.
 * Shows appointment details in a clean print-optimized layout.
 *
 * Permissions: Admin, Doctor (if their appointment), Patient (if their appointment)
 */

require_once __DIR__ . '/../../includes/auth.php';
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

$appointmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$appointmentId) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>Appointment ID is required.</p>';
    exit;
}

try {
    $db = getDB();

    // Get appointment with related data
    $stmt = $db->prepare(
        "SELECT a.*, u.name as patient_user_name, u2.name as doctor_user_name
         FROM appointments a
         LEFT JOIN users u ON a.user_id = u.id
         LEFT JOIN users u2 ON a.doctor_id = u2.id
         WHERE a.id = ? LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1><p>Appointment not found.</p>';
        exit;
    }

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

    // Get visit note
    $vnStmt = $db->prepare("SELECT * FROM visit_notes WHERE appointment_id = ? LIMIT 1");
    $vnStmt->execute([$appointmentId]);
    $visitNote = $vnStmt->fetch();

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
    <style>
        @page { margin: 15mm 18mm; size: A4 portrait; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a2e; background: #fff; font-size: 11pt; line-height: 1.5; margin: 0; padding: 0; }
        .print-container { max-width: 100%; padding: 0; }
        .header { text-align: center; padding-bottom: 15px; border-bottom: 2px solid #0a9396; margin-bottom: 20px; }
        .header h1 { font-size: 20pt; margin: 0; color: #0a9396; }
        .header p { font-size: 9pt; color: #666; margin: 4px 0 0 0; }
        .info-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px 18px; margin-bottom: 18px; }
        .info-card h2 { font-size: 14pt; margin: 0 0 8px 0; color: #0369a1; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; font-size: 10pt; }
        .info-grid .label { color: #666; }
        section { margin-bottom: 18px; }
        section h3 { font-size: 12pt; color: #0a9396; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 8px; }
        .record-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; }
        .record-card p { margin: 2px 0; font-size: 9pt; }
        .footer { text-align: center; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 20px; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 9pt; font-weight: 600; }
        .status-badge.Confirmed { background: #dcfce7; color: #166534; }
        .status-badge.Pending { background: #fef9c3; color: #854d0e; }
        .status-badge.Cancelled { background: #fee2e2; color: #991b1b; }
    </style>
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
                <div><span class="label">Status:</span> <span class="status-badge <?= htmlspecialchars($appt['status']) ?>"><?= htmlspecialchars($appt['status']) ?></span></div>
                <div><span class="label">Doctor:</span> <?= htmlspecialchars($appt['doctor'] ?: ($appt['doctor_user_name'] ?? '—')) ?></div>
                <div><span class="label">Department:</span> <?= htmlspecialchars($appt['department']) ?></div>
                <div><span class="label">Date:</span> <?= htmlspecialchars($appt['date']) ?></div>
                <div><span class="label">Time:</span> <?= htmlspecialchars($appt['appointment_time_range'] ?? $appt['time']) ?></div>
                <?php if ($appt['notes']): ?>
                <div style="grid-column: 1 / -1;"><span class="label">Notes:</span> <?= htmlspecialchars($appt['notes']) ?></div>
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

    <script>
        window.onload = function() {
            window.print();
            setTimeout(function() { window.close(); }, 500);
        };
    </script>
</body>
</html>
<?php
} catch (Exception $e) {
    error_log('Print Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Server Error</h1><p>Failed to generate appointment print page.</p>';
}

