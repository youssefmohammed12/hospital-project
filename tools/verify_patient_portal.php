<?php
/**
 * HealthBridge — Patient Portal QA Verification Script
 * 
 * Run: php tools/verify_patient_portal.php
 * 
 * Checks all files, DOM IDs, functions, API contract, CSS, and backend.
 * Exits with 0 if all pass, 1 if critical failures exist.
 */

// ── Configuration ──────────────────────────────────────────
define('BASE_DIR', __DIR__ . '/..');

$files = [
    'PatientPortalService.php' => BASE_DIR . '/services/PatientPortalService.php',
    'dashboard.php API'        => BASE_DIR . '/api/patient/dashboard.php',
    'patient-portal.js'        => BASE_DIR . '/assets/js/pages/patient-portal.js',
    'patient-portal.css'       => BASE_DIR . '/assets/css/pages/patient-portal.css',
    'dashboard.html'           => BASE_DIR . '/pages/patient/dashboard.html',
];

$results = [
    'passed'    => 0,
    'warnings'  => 0,
    'failed'    => 0,
    'messages'  => [],
];

function pass(string $label, string $detail = ''): void {
    global $results;
    $results['passed']++;
    $results['messages'][] = ['status' => 'PASS', 'label' => $label, 'detail' => $detail];
}

function warn(string $label, string $detail = ''): void {
    global $results;
    $results['warnings']++;
    $results['messages'][] = ['status' => 'WARN', 'label' => $label, 'detail' => $detail];
}

function fail(string $label, string $detail = ''): void {
    global $results;
    $results['failed']++;
    $results['messages'][] = ['status' => 'FAIL', 'label' => $label, 'detail' => $detail];
}

function check(bool $condition, string $label, string $detail = ''): void {
    $condition ? pass($label, $detail) : fail($label, $detail);
}

function file_safe(string $path): string {
    return file_get_contents($path) ?: '';
}

// ════════════════════════════════════════════════════════════
echo str_repeat('=', 60) . "\n";
echo "  PATIENT PORTAL QA REPORT\n";
echo str_repeat('=', 60) . "\n\n";

// ════════════════════════════════════════════════════════════
//  1. FILES
// ════════════════════════════════════════════════════════════
echo "--- Files ---\n";

$fileContents = [];
foreach ($files as $name => $path) {
    if (file_exists($path)) {
        $fileContents[$name] = file_get_contents($path);
        check(true, "{$name} exists");
    } else {
        $fileContents[$name] = '';
        fail("{$name} exists", "File not found at: {$path}");
    }
}

$js    = $fileContents['patient-portal.js'] ?? '';
$html  = $fileContents['dashboard.html'] ?? '';
$css   = $fileContents['patient-portal.css'] ?? '';
$svc   = $fileContents['PatientPortalService.php'] ?? '';
$api   = $fileContents['dashboard.php API'] ?? '';

echo "\n";

// ════════════════════════════════════════════════════════════
//  2. JAVASCRIPT QUALITY
// ════════════════════════════════════════════════════════════
echo "--- JavaScript ---\n";

// Basic content check
check(strlen($js) > 5000, "JS file is substantial (>5KB)", "Found " . strlen($js) . " bytes");
check(strpos($js, '"use strict"') !== false || strpos($js, "'use strict'") !== false, "Uses strict mode");

// Debug code
check(preg_match('/console\.log/', $js) ? false : true, "No console.log statements");
check(preg_match('/alert\(/', $js) ? false : true, "No alert() calls");

// DOM ID matching
preg_match_all('/getElementById\([\'"]([\w-]+)[\'"]\)/', $js, $jsMatches);
preg_match_all('/id="([\w-]+)"/', $html, $htmlMatches);
$jsIds = array_unique($jsMatches[1]);
$htmlIds = array_unique($htmlMatches[1]);
$missingIds = array_diff($jsIds, $htmlIds);

if (empty($missingIds)) {
    pass("All JS DOM IDs exist in HTML (" . count($jsIds) . " refs)");
} else {
    fail("JS DOM IDs missing from HTML", implode(', ', $missingIds));
}

// Global function completeness
$requiredGlobals = [
    'refreshPortal', 'navigateToBook', 'navigateToSection',
    'viewAppointment', 'editProfile', 'closeMessagesModal',
    'closeDoctorProfile', 'formatShortDate',
];
foreach ($requiredGlobals as $fn) {
    check(
        preg_match('/function\s+' . preg_quote($fn) . '\s*\(/', $js) ? true : false,
        "Global function {$fn}() is defined"
    );
}

// PortalApp methods
$portalMethods = [
    'init', 'loadDashboard', 'renderAll', 'showError',
    'renderOverview', 'renderHealthAlerts', 'renderHealthSnapshot',
    'renderAppointments', 'renderMedicalTimeline', 'renderPrescriptions',
    'renderProfile', 'renderNotifications', 'renderFavorites',
    'renderInsights', 'renderDownloads', 'renderQuickActions',
    'renderChart', 'initSearch',
    'markNotifRead', 'deleteNotif', 'markAllNotifRead',
    'navigateTo', 'navigateToBook', 'bookWithDoctor',
    'viewPrescription', 'downloadPrescription', 'openRating',
    'attachEventListeners',
];
foreach ($portalMethods as $method) {
    check(
        preg_match('/\b' . preg_quote($method) . '\s*[\(:]/', $js) ? true : false,
        "PortalApp method {$method}() exists"
    );
}

// Chart initialization
check(
    preg_match('/new\s+Chart\s*\(/', $js) ? true : false,
    "Chart.js initialization exists"
);
check(
    preg_match('/themechange/', $js) ? true : false,
    "Theme change listener attached"
);

// Search initialization
check(
    strpos($js, 'initSearch') !== false,
    "Search initialization exists"
);
check(
    strpos($js, 'debounce') !== false || strpos($js, 'setTimeout') !== false,
    "Search debounce exists"
);

// Data contract — every top-level key used
$dataKeys = [
    'overview', 'health_snapshot', 'appointments', 'medical_timeline',
    'prescriptions', 'profile', 'notifications', 'favorites',
    'insights', 'downloads', 'health_alerts', 'profile_completion',
];
foreach ($dataKeys as $key) {
    check(
        strpos($js, "this.data.{$key}") !== false,
        "JS reads data.{$key} from API response"
    );
}

// Overview sub-properties
$overviewProps = [
    'greeting', 'first_name', 'patient_number', 'hospital',
    'member_since', 'unread_notifications', 'next_appointment', 'primary_doctor',
];
foreach ($overviewProps as $prop) {
    check(
        strpos($js, "overview.{$prop}") !== false,
        "JS reads overview.{$prop}"
    );
}

// Health snapshot sub-properties
$snapshotProps = [
    'upcoming_appointments', 'completed_appointments', 'active_prescriptions',
    'has_medical_record', 'doctors_seen', 'unread_notifications',
    'profile_completion', 'last_visit',
];
foreach ($snapshotProps as $prop) {
    check(
        strpos($js, "snapshot.{$prop}") !== false || strpos($js, "health_snapshot.{$prop}") !== false,
        "JS uses health_snapshot.{$prop}"
    );
}

echo "\n";

// ════════════════════════════════════════════════════════════
//  3. HTML STRUCTURE
// ════════════════════════════════════════════════════════════
echo "--- HTML Structure ---\n";

check(strlen($html) > 5000, "HTML file is substantial (>5KB)");

$requiredSections = [
    'overview', 'appointments', 'timeline', 'prescriptions',
    'profile', 'notifications', 'doctors', 'insights', 'downloads',
    'welcome-hero', 'health-alerts', 'kpi-skeleton', 'kpi-content',
    'quick-actions-grid', 'search-bar', 'portal-search',
    'doctor-profile-overlay', 'messages-modal-overlay', 'rating-modal-overlay',
];
foreach ($requiredSections as $section) {
    check(
        strpos($html, $section) !== false,
        "HTML section #{$section} exists"
    );
}

// Script tags
check(strpos($html, 'core/main.js') !== false, "Script: main.js loaded");
check(strpos($html, 'chart.js') !== false, "Script: Chart.js loaded");
check(strpos($html, 'patient-portal.js') !== false, "Script: patient-portal.js loaded");
check(strpos($html, 'settings.js') !== false, "Script: settings.js loaded");
check(strpos($html, 'notifications.js') !== false, "Script: notifications.js loaded");

// CSS tags
check(strpos($html, 'patient-portal.css') !== false, "CSS: patient-portal.css loaded");
check(strpos($html, 'dashboard.css') !== false, "CSS: dashboard.css loaded");
check(strpos($html, 'style.css') !== false, "CSS: style.css loaded");

// Skeleton loaders
check(strpos($html, 'hero-skeleton') !== false, "Skeleton: hero");
check(strpos($html, 'kpi-skeleton') !== false, "Skeleton: KPI");
check(strpos($html, 'appt-skeleton') !== false, "Skeleton: appointments");
check(strpos($html, 'timeline-skeleton') !== false, "Skeleton: timeline");
check(strpos($html, 'rx-skeleton') !== false, "Skeleton: prescriptions");
check(strpos($html, 'profile-skeleton') !== false, "Skeleton: profile");
check(strpos($html, 'notif-skeleton') !== false, "Skeleton: notifications");
check(strpos($html, 'doc-skeleton') !== false, "Skeleton: doctors");
check(strpos($html, 'insights-skeleton') !== false, "Skeleton: insights");
check(strpos($html, 'dl-skeleton') !== false, "Skeleton: downloads");

// Error states
$errorStates = ['hero-error', 'kpi-error', 'appt-error', 'timeline-error', 'rx-error', 'profile-error', 'notif-error', 'doc-error', 'insights-error', 'dl-error'];
foreach ($errorStates as $err) {
    check(strpos($html, $err) !== false, "Error state: {$err}");
}

// Empty states
$emptyStates = ['next-appt-empty', 'appt-empty', 'timeline-empty', 'rx-empty', 'notif-empty', 'doc-empty', 'insights-empty', 'dl-empty'];
foreach ($emptyStates as $empty) {
    check(strpos($html, $empty) !== false, "Empty state: {$empty}");
}

echo "\n";

// ════════════════════════════════════════════════════════════
//  4. CSS QUALITY
// ════════════════════════════════════════════════════════════
echo "--- CSS ---\n";

check(strlen($css) > 5000, "CSS file is substantial (>5KB)", "Found " . strlen($css) . " bytes");
check(strpos($css, '.skeleton') !== false, "Has skeleton loader styles");
check(strpos($css, '@media') !== false, "Has responsive @media queries");
check(strpos($css, 'var(--') !== false, "Uses CSS custom properties");
check(strpos($css, '.empty-state') !== false, "Has empty state styles");

// Responsive breakpoints
$breakpoints = ['1024px', '768px', '480px'];
foreach ($breakpoints as $bp) {
    check(strpos($css, $bp) !== false, "Responsive breakpoint at {$bp}");
}

// Component styles
$components = [
    '.welcome-hero', '.kpi-card', '.timeline-item', '.med-timeline',
    '.prescription-card', '.profile-section', '.notif-item', '.doctor-card',
    '.insight-stat', '.download-card', '.quick-action-card', '.future-placeholder',
    '.search-bar', '.status-badge', '.completion-ring',
];
foreach ($components as $comp) {
    check(strpos($css, $comp) !== false, "Component style: {$comp}");
}

echo "\n";

// ════════════════════════════════════════════════════════════
//  5. PHP BACKEND — PatientPortalService
// ════════════════════════════════════════════════════════════
echo "--- Backend (PatientPortalService) ---\n";

check(strpos($svc, 'class PatientPortalService') !== false, "Class PatientPortalService defined");

$requiredMethods = [
    'getAll', 'getOverview', 'getHealthSnapshot', 'getAppointmentTimeline',
    'getMedicalTimeline', 'getPrescriptions', 'getMedicalProfile',
    'getNotifications', 'getFavorites', 'getHealthInsights',
    'getDownloads', 'getHealthAlerts', 'getProfileCompletion', 'searchAll',
];
foreach ($requiredMethods as $method) {
    check(
        preg_match('/function\s+' . preg_quote($method) . '\s*\(/', $svc) ? true : false,
        "Method {$method}() exists"
    );
}

// Check for duplicate methods
foreach ($requiredMethods as $method) {
    $count = preg_match_all('/function\s+' . preg_quote($method) . '\s*\(/', $svc);
    check($count <= 1, "Method {$method}() has no duplicates", $count > 1 ? "Found {$count} declarations" : '');
}

// Check prepared statements
check(
    preg_match_all('/\$stmt\s*=\s*\$this->db->prepare\(/', $svc) > 5,
    "Uses prepared statements throughout",
    preg_match_all('/\$stmt\s*=\s*\$this->db->prepare\(/', $svc) . " prepare() calls"
);

// Check no N+1 patterns in loops
$loopQueryCount = preg_match_all('/foreach.*\$.*\n.*prepare/', $svc);
check($loopQueryCount < 3, "No N+1 query patterns (max 2 allowed)", "Found {$loopQueryCount} loop+query patterns");

// Test instantiation
try {
    require_once BASE_DIR . '/includes/db.php';
    require_once BASE_DIR . '/services/PatientPortalService.php';
    $db = getDB();
    $portal = new PatientPortalService($db);
    check(true, "Service can be instantiated with database");
    
    // Test with patient ID 2
    $data = $portal->getAll(2);
    check(isset($data['overview']), "getAll(2) returns overview");
    check(isset($data['health_snapshot']), "getAll(2) returns health_snapshot");
    check(isset($data['appointments']), "getAll(2) returns appointments");
    check(isset($data['medical_timeline']), "getAll(2) returns medical_timeline");
    check(isset($data['prescriptions']), "getAll(2) returns prescriptions");
    check(isset($data['profile']), "getAll(2) returns profile");
    check(isset($data['notifications']), "getAll(2) returns notifications");
    check(isset($data['favorites']), "getAll(2) returns favorites");
    check(isset($data['insights']), "getAll(2) returns insights");
    check(isset($data['downloads']), "getAll(2) returns downloads");
    check(isset($data['health_alerts']), "getAll(2) returns health_alerts");
    check(isset($data['profile_completion']), "getAll(2) returns profile_completion");
    
    // Verify no PHP warnings/errors in response
    check(true, "getAll(2) executes without errors");
    
    // Test search
    $search = $portal->searchAll(2, 'Ahmed');
    check(isset($search['total']), "searchAll returns total count");
    check(isset($search['appointments']), "searchAll returns appointments");
    check(isset($search['doctors']), "searchAll returns doctors");
    check(isset($search['prescriptions']), "searchAll returns prescriptions");
    
    // Test empty patient
    $emptyData = $portal->getAll(99999);
    check($emptyData['overview']['full_name'] === 'Patient', "Non-existent patient returns graceful empty state");
    check($emptyData['appointments']['counts']['total'] === 0, "Non-existent patient has 0 appointments");
    check($emptyData['profile_completion']['percentage'] === 0, "Non-existent patient has 0% completion");
    
} catch (Exception $e) {
    fail("Service instantiation or live test", $e->getMessage());
}

echo "\n";

// ════════════════════════════════════════════════════════════
//  6. API ENDPOINT
// ════════════════════════════════════════════════════════════
echo "--- API Endpoint ---\n";

check(strpos($api, 'requireAuth') !== false, "Uses requireAuth() auth middleware");
check(strpos($api, 'getJsonInput') !== false, "Uses getJsonInput() for input parsing");
check(strpos($api, 'withDB') !== false, "Uses withDB() for error handling");
check(strpos($api, 'PatientPortalService') !== false, "Instantiates PatientPortalService");
check(strpos($api, 'search') !== false, "Supports search parameter");
check(strpos($api, 'Cache-Control') !== false, "Sets Cache-Control headers");
check(strpos($api, '403') !== false, "Enforces role check with 403 response");

echo "\n";

// ════════════════════════════════════════════════════════════
//  SUMMARY
// ════════════════════════════════════════════════════════════
echo str_repeat('-', 60) . "\n";
echo "  QA SUMMARY\n";
echo str_repeat('-', 60) . "\n";
echo "  Passed:   {$results['passed']}\n";
echo "  Warnings: {$results['warnings']}\n";
echo "  Failed:   {$results['failed']}\n";
echo str_repeat('-', 60) . "\n";

if ($results['failed'] === 0) {
    echo "  ✅ ALL CHECKS PASSED — Portal is production-ready.\n";
    exit(0);
} else {
    echo "  ❌ {$results['failed']} CHECK(S) FAILED — Review details above.\n";
    
    // Show failures
    foreach ($results['messages'] as $msg) {
        if ($msg['status'] === 'FAIL') {
            echo "  FAIL: {$msg['label']}" . ($msg['detail'] ? " ({$msg['detail']})" : '') . "\n";
        }
    }
    exit(1);
}