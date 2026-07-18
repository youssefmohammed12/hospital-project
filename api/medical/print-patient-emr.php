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
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if ($currentRole === 'patient') {
    $patientId = $currentUserId;
} elseif (!$patientId) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>Patient ID is required.</p>';
    exit;
}

try {
    $db = getDB();

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

    // Auth check for doctor
    if ($currentRole === 'doctor') {
        $authStmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id = ? AND user_id = ? AND status IN ('Confirmed','Cancelled') LIMIT 1");
        $authStmt->execute([$currentUserId, $patientId]);
        if ((int)$authStmt->fetch()['cnt'] === 0) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>You can only view records of patients you have treated.</p>';
            exit;
        }
    }

    // Medical record
    $mrStmt = $db->prepare("SELECT * FROM medical_records WHERE patient_id = ? LIMIT 1");
    $mrStmt->execute([$patientId]);
    $mr = $mrStmt->fetch();

    // Age
    $age = null;
    if ($mr && !empty($mr['date_of_birth'])) {
        $age = (new DateTime($mr['date_of_birth']))->diff(new DateTime())->y;
    }

    // Appointments
    $apptStmt = $db->prepare("SELECT a.* FROM appointments a WHERE a.user_id = ? ORDER BY a.date DESC LIMIT 20");
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();
    foreach ($appointments as &$a) {
        if (empty($a['appointment_time_range'])) {
            $a['appointment_time_range'] = computeAppointmentTimeRange($a['time'], getAppointmentDuration((int)$a['doctor_id']));
        }
    }
    unset($a);

    // Prescriptions
    $rxStmt = $db->prepare(
        "SELECT p.*, u.name as doctor_name, a.date as appt_date,
                (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = p.id) as item_count
         FROM prescriptions p JOIN users u ON p.doctor_id = u.id
         JOIN appointments a ON p.appointment_id = a.id
         WHERE p.patient_id = ? ORDER BY p.created_at DESC LIMIT 10"
    );
    $rxStmt->execute([$patientId]);
    $prescriptions = $rxStmt->fetchAll();

    // Visit notes
    $visitStmt = $db->prepare(
        "SELECT vn.*, a.date as appt_date, u.name as doctor_name
         FROM visit_notes vn JOIN appointments a ON vn.appointment_id = a.id
         LEFT JOIN users u ON vn.doctor_id = u.id
         WHERE vn.patient_id = ? ORDER BY vn.created_at DESC LIMIT 10"
    );
    $visitStmt->execute([$patientId]);
    $visitNotes = $visitStmt->fetchAll();

    // Stats
    $totalAppts = count($appointments);
    $completedAppts = 0;
    $upcomingAppts = 0;
    $cancelledAppts = 0;
    foreach ($appointments as $a) {
        if ($a['status'] === 'Confirmed') {
            if ($a['date'] >= date('Y-m-d')) $upcomingAppts++;
            else $completedAppts++;
        } elseif ($a['status'] === 'Cancelled') $cancelledAppts++;
        elseif ($a['status'] === 'Pending') $upcomingAppts++;
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
    <style>
        @page { margin: 15mm 18mm; size: A4 portrait; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a2e; background: #fff; font-size: 11pt; line-height: 1.5; margin: 0; padding: 0; }
        .print-container { max-width: 100%; padding: 0; }
        .header { text-align: center; padding-bottom: 15px; border-bottom: 2px solid #0a9396; margin-bottom: 20px; }
        .header h1 { font-size: 20pt; margin: 0; color: #0a9396; }
        .header p { font-size: 9pt; color: #666; margin: 4px 0 0 0; }
        .patient-info { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px 18px; margin-bottom: 18px; }
        .patient-info h2 { font-size: 16pt; margin: 0 0 8px 0; color: #0369a1; }
        .patient-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; font-size: 10pt; }
        .patient-grid .label { color: #666; }
        .stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 14px; text-align: center; flex: 1; min-width: 80px; }
        .stat-box .num { font-size: 16pt; font-weight: 700; color: #0a9396; }
        .stat-box .lbl { font-size: 7.5pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        section { margin-bottom: 18px; }
        section h3 { font-size: 12pt; color: #0a9396; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 8px; }
        th { background: #f1f5f9; text-align: left; padding: 5px 8px; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3px; color: #475569; border-bottom: 1px solid #cbd5e1; }
        td { padding: 4px 8px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .record-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; }
        .record-card p { margin: 2px 0; font-size: 9pt; }
        .footer { text-align: center; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 20px; }
        .no-data { color: #94a3b8; font-style: italic; font-size: 9pt; }
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
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
            <h2><?= htmlspecialchars($patient['name']) ?> <span style="font-size:10pt;font-weight:400;color:#64748b">#<?= $patient['id'] ?></span></h2>
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
                <span style="float:right;font-size:8pt;color:#64748b"><?= htmlspecialchars($rx['status']) ?></span>
                <p style="font-size:8pt;color:#64748b"><?= date('M j, Y', strtotime($rx['created_at'])) ?> &middot; <?= $rx['item_count'] ?> medication(s)</p>
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
    error_log('Print EMR Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Server Error</h1><p>Failed to generate EMR print page.</p>';
}

