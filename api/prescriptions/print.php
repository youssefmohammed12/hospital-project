<?php
/**
 * HealthBridge — Standalone Prescription Print Page
 * 
 * A dedicated printable prescription page with no dashboard UI.
 * Opens in a new tab, auto-prints, and auto-closes if possible.
 * 
 * Permissions:
 *   - Patient: can print only their own prescriptions
 *   - Doctor: can print only prescriptions they created
 *   - Admin: can print every prescription
 * 
 * Future: This page can be used directly by DomPDF/TCPDF/mPDF
 * for PDF generation without needing another HTML template.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

// Override Content-Type from application/json (set by auth_middleware's sendCorsHeaders)
// to text/html since this is a standalone printable page, not an API endpoint.
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

$prescriptionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$prescriptionId) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>Prescription ID is required.</p>';
    exit;
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);
    $rx = $ps->get($prescriptionId);

    if (!$rx) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1><p>Prescription not found.</p>';
        exit;
    }

    // ── Permission Check ──
    if ($currentRole === 'patient' && (int)$rx['patient_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You can only view your own prescriptions.</p>';
        exit;
    }

    if ($currentRole === 'doctor' && (int)$rx['doctor_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You can only view prescriptions you created.</p>';
        exit;
    }

    // ── Format data ──
    $rxIdFormatted = 'RX-' . date('Y') . '-' . str_pad($rx['id'], 6, '0', STR_PAD_LEFT);
    $statusClass = strtolower($rx['status'] ?? 'active');
    $items = $rx['items'] ?? [];
    $doctorSpecialty = $rx['appt_department'] ?? 'Medical Department';
    $issueDate = date('F j, Y', strtotime($rx['created_at']));
    $updatedDate = $rx['updated_at'] && $rx['updated_at'] !== $rx['created_at'] 
        ? date('F j, Y', strtotime($rx['updated_at'])) 
        : null;

    // Determine dashboard URL for return button
    $dashboardUrl = '';
    if ($currentRole === 'patient') $dashboardUrl = '../../pages/patient/dashboard.html#prescriptions';
    elseif ($currentRole === 'doctor') $dashboardUrl = '../../pages/doctor/doctor-dashboard.html#prescriptions';
    elseif ($currentRole === 'admin') $dashboardUrl = '../../pages/admin/admin.html#prescriptions';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription <?php echo htmlspecialchars($rxIdFormatted); ?> — HealthBridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/modules/print-prescription.css">
</head>
<body>

    <!-- Hospital Header -->
    <div class="print-header">
        <div class="print-logo">
            <i class="fas fa-hospital" aria-hidden="true"></i>
        </div>
        <div class="print-logo-text">
            <h1>HealthBridge Hospital</h1>
            <p>123 Healthcare Avenue, Medical District &middot; Tel: +1 (555) 123-4567 &middot; Email: info@healthbridge.com</p>
        </div>
    </div>

    <!-- Meta Row -->
    <div class="print-meta">
        <div>
            <span class="print-rx-id"><?php echo htmlspecialchars($rxIdFormatted); ?></span>
            <div class="print-date"><i class="fas fa-calendar" aria-hidden="true"></i> Issued: <?php echo htmlspecialchars($issueDate); ?></div>
            <?php if ($updatedDate): ?>
                <div class="print-updated"><i class="fas fa-pen" aria-hidden="true"></i> Last Updated: <?php echo htmlspecialchars($updatedDate); ?></div>
            <?php endif; ?>
        </div>
        <div>
            <span class="print-status print-status-<?php echo htmlspecialchars($statusClass); ?>">
                <?php echo htmlspecialchars($rx['status']); ?>
            </span>
        </div>
    </div>

    <!-- Cancellation Reason -->
    <?php if ($rx['status'] === 'Cancelled' && !empty($rx['cancellation_reason'])): ?>
        <div class="print-cancel-reason">
            <strong><i class="fas fa-ban" aria-hidden="true"></i> Cancellation Reason</strong>
            <p><?php echo htmlspecialchars($rx['cancellation_reason']); ?></p>
        </div>
    <?php endif; ?>

    <!-- Info Grid -->
    <div class="print-info-grid">
        <div class="print-info-box">
            <h3><i class="fas fa-user" aria-hidden="true"></i> Patient Information</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo htmlspecialchars($rx['patient_name'] ?? 'N/A'); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Email</span>
                <span class="print-info-value"><?php echo htmlspecialchars($rx['patient_email'] ?? '—'); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3><i class="fas fa-user-doctor" aria-hidden="true"></i> Doctor Information</h3>
            <div class="print-info-row">
                <span class="print-info-label">Name</span>
                <span class="print-info-value"><?php echo htmlspecialchars($rx['doctor_name'] ?? $rx['appt_doctor_name'] ?? 'N/A'); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Department</span>
                <span class="print-info-value"><?php echo htmlspecialchars($doctorSpecialty); ?></span>
            </div>
        </div>
        <div class="print-info-box">
            <h3><i class="fas fa-calendar-check" aria-hidden="true"></i> Appointment</h3>
            <div class="print-info-row">
                <span class="print-info-label">Date</span>
                <span class="print-info-value"><?php echo htmlspecialchars(date('F j, Y', strtotime($rx['appt_date']))); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Time</span>
                <span class="print-info-value"><?php echo htmlspecialchars($rx['appt_time'] ?? '—'); ?></span>
            </div>
            <div class="print-info-row">
                <span class="print-info-label">Department</span>
                <span class="print-info-value"><?php echo htmlspecialchars($rx['appt_department'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Medications -->
    <div class="print-meds-section">
        <div class="print-meds-title">
            <i class="fas fa-capsules" aria-hidden="true"></i> Prescribed Medications
            <span style="font-weight:400;font-size:9pt;color:#666"> (<?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>)</span>
        </div>

        <?php if (empty($items)): ?>
            <p style="color:#666;font-size:9pt;padding:12px 0;text-align:center">No medication items found.</p>
        <?php else: ?>
            <table class="print-meds-table">
                <thead>
                    <tr>
                        <th style="width:5%">#</th>
                        <th style="width:22%">Medication</th>
                        <th style="width:12%">Strength</th>
                        <th style="width:12%">Dosage</th>
                        <th style="width:15%">Frequency</th>
                        <th style="width:10%">Duration</th>
                        <th style="width:24%">Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td class="med-name"><?php echo htmlspecialchars($item['medication_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['strength']); ?></td>
                        <td><?php echo htmlspecialchars($item['dosage']); ?></td>
                        <td><?php echo htmlspecialchars($item['frequency']); ?></td>
                        <td><?php echo htmlspecialchars($item['duration']); ?></td>
                        <td><?php echo htmlspecialchars($item['instructions'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Notes -->
    <?php if (!empty($rx['notes'])): ?>
    <div class="print-notes">
        <h3><i class="fas fa-sticky-note" aria-hidden="true"></i> Prescription Notes</h3>
        <p><?php echo htmlspecialchars($rx['notes']); ?></p>
    </div>
    <?php endif; ?>

    <!-- Signature -->
    <div class="print-signature">
        <div class="print-signature-left">
            <h3><i class="fas fa-pen" aria-hidden="true"></i> Prescribing Doctor</h3>
            <div class="print-signature-name"><?php echo htmlspecialchars($rx['doctor_name'] ?? $rx['appt_doctor_name'] ?? 'N/A'); ?></div>
            <div class="print-signature-details"><?php echo htmlspecialchars($doctorSpecialty); ?></div>
            <div class="print-signature-line">Electronic Signature</div>
        </div>
        <div class="print-signature-right">
            <i class="fas fa-qrcode" style="font-size:18pt" aria-hidden="true"></i><br>
            <?php echo htmlspecialchars($rxIdFormatted); ?>
        </div>
    </div>

    <!-- Return Button (shown if auto-close fails) -->
    <div class="print-return" id="print-return">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Return to Dashboard</a>
        <p>If the window did not close automatically, click the button above.</p>
    </div>

    <script>
    // Auto-print on load
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 300);
    };

    // Auto-close after printing
    window.onafterprint = function() {
        // Try to close the window (only works for windows opened via window.open)
        window.close();
    };

    // Fallback: if afterprint doesn't fire, listen for matchMedia change
    var printMedia = window.matchMedia('print');
    printMedia.addEventListener('change', function(e) {
        if (!e.matches) {
            // Print dialog was closed — try to close window
            setTimeout(function() {
                window.close();
            }, 200);
        }
    });
    </script>

</body>
</html>
<?php
} catch (Exception $e) {
    error_log('Print Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Internal Server Error</h1><p>Failed to load prescription for printing.</p>';
}
