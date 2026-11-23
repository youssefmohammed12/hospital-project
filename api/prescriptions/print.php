<?php
/**
 * HealthBridge — Standalone Prescription Print Page
 * 
 * A dedicated printable prescription page with no dashboard UI.
 * Opens in a new tab, auto-prints, and auto-closes if possible.
 * 
 * Permissions:
 *   - Patient: can print only their own prescriptions
 *   - Doctor: can print any prescription (read-only record access —
 *     matches api/prescriptions/get.php; editing stays author-only)
 *   - Admin: can print every prescription
 * 
 * Future: This page can be used directly by DomPDF/TCPDF/mPDF
 * for PDF generation without needing another HTML template.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/PrintOutputContext.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/HospitalSettingsService.php';

// Override Content-Type from application/json (set by auth_middleware's sendCorsHeaders)
// to text/html since this is a standalone printable page, not an API endpoint.
header('Content-Type: text/html; charset=utf-8');

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '<h1>405 Method Not Allowed</h1><p>This document is available with GET requests only.</p>';
    exit;
}

$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if (!in_array($currentRole, ['admin', 'doctor', 'patient'], true)) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to view prescriptions.</p>';
    exit;
}
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$idValue = $_GET['id'] ?? null;
$normalizedId = is_string($idValue) ? ltrim($idValue, '0') : '';
$prescriptionId = is_string($idValue) && ctype_digit($idValue) ? (int)$idValue : 0;

if ($prescriptionId < 1 || (string)$prescriptionId !== $normalizedId) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>A valid positive prescription ID is required.</p>';
    exit;
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PRESCRIPTIONS_SCHEMA'));
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

    // Doctors may print any prescription: it is the same read access as
    // get.php (a treating doctor may need the medication record for the
    // chart, whoever prescribed it). Write access stays author-only.

    // ── Format data ──
    $rxIdFormatted = 'RX-' . date('Y') . '-' . str_pad($rx['id'], 6, '0', STR_PAD_LEFT);
    $statusClass = PrintOutputContext::prescriptionStatusClass($rx['status'] ?? null);
    $items = $rx['items'] ?? [];
    $doctorSpecialty = $rx['appt_department'] ?? 'Medical Department';
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
    $issueDate = date('F j, Y', strtotime($rx['created_at']));
    $updatedDate = $rx['updated_at'] && $rx['updated_at'] !== $rx['created_at'] 
        ? date('F j, Y', strtotime($rx['updated_at'])) 
        : null;

    // Determine dashboard URL for return button
    $dashboardUrl = '';
    if ($currentRole === 'patient') $dashboardUrl = '../../pages/patient/prescriptions.html';
    elseif ($currentRole === 'doctor') $dashboardUrl = '../../pages/doctor/doctor-dashboard.html#prescriptions';
    elseif ($currentRole === 'admin') $dashboardUrl = '../../pages/admin/prescriptions.html';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription <?php echo htmlspecialchars($rxIdFormatted); ?> — HealthBridge</title>
    <link rel="stylesheet" href="../../assets/public-v2/css/pages/print-prescription.css?v=1">
</head>
<body>

    <!-- Hospital Header -->
    <div class="print-header">
        <div class="print-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="#a9844f" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V8l8-5 8 5v13"/><path d="M2 21h20"/><path d="M12 8v4M10 10h4"/><path d="M8 21v-4h8v4"/></svg>
        </div>
        <div class="print-logo-text">
            <h1><?php echo htmlspecialchars($hospitalName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p><?php echo $hospitalContactLine !== '' ? $hospitalContactLine : 'Hospital contact details are not configured.'; ?></p>
        </div>
    </div>

    <!-- Meta Row -->
    <div class="print-meta">
        <div>
            <span class="print-rx-id"><?php echo htmlspecialchars($rxIdFormatted); ?></span>
            <div class="print-date">Issued: <?php echo htmlspecialchars($issueDate); ?></div>
            <?php if ($updatedDate): ?>
                <div class="print-updated">Last Updated: <?php echo htmlspecialchars($updatedDate); ?></div>
            <?php endif; ?>
        </div>
        <div>
            <span class="print-status print-status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($rx['status']); ?>
            </span>
        </div>
    </div>

    <!-- Cancellation Reason -->
    <?php if ($rx['status'] === 'Cancelled' && !empty($rx['cancellation_reason'])): ?>
        <div class="print-cancel-reason">
            <strong>Cancellation Reason</strong>
            <p><?php echo htmlspecialchars($rx['cancellation_reason']); ?></p>
        </div>
    <?php endif; ?>

    <!-- Info Grid -->
    <div class="print-info-grid">
        <div class="print-info-box">
            <h3>Patient Information</h3>
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
            <h3>Doctor Information</h3>
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
            <h3>Appointment</h3>
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
            Prescribed Medications
            <span class="print-meds-count"> (<?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>)</span>
        </div>

        <?php if (empty($items)): ?>
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
            </div>
        <?php endif; ?>
    </div>

    <!-- Notes -->
    <?php if (!empty($rx['notes'])): ?>
    <div class="print-notes">
        <h3>Prescription Notes</h3>
        <p><?php echo htmlspecialchars($rx['notes']); ?></p>
    </div>
    <?php endif; ?>

    <!-- Signature -->
    <div class="print-signature">
        <div class="print-signature-left">
            <h3>Prescribing Doctor</h3>
            <div class="print-signature-name"><?php echo htmlspecialchars($rx['doctor_name'] ?? $rx['appt_doctor_name'] ?? 'N/A'); ?></div>
            <div class="print-signature-details"><?php echo htmlspecialchars($doctorSpecialty); ?></div>
            <div class="print-signature-line">Prescriber recorded on this prescription</div>
        </div>
        <div class="print-signature-right">
            <span>Document reference</span><br>
            <strong><?php echo htmlspecialchars($rxIdFormatted, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- Return Button (shown if auto-close fails) -->
    <div class="print-return" id="print-return">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>">← Return to your workspace</a>
        <p>If the window did not close automatically, click the button above.</p>
    </div>

        <script src="../../assets/public-v2/js/pages/prescription-print.js?v=1"></script>

</body>
</html>
<?php
} catch (Throwable $e) {
    error_log('Print Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>500 Internal Server Error</h1><p>Failed to load prescription for printing.</p>';
}
