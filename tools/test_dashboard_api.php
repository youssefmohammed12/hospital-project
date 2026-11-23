<?php
/**
 * CLI test script for PatientPortalService.
 *
 * Safe use (PowerShell):
 *   $env:DB_HOST = "127.0.0.1"
 *   $env:DB_NAME = "healthbridge_repair_test"
 *   $env:HB_ALLOW_LOCAL_DB_DIAGNOSTICS = "1"
 *   php tools/test_dashboard_api.php
 *
 * Refuses hosted/live databases before opening a connection.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$diagnosticHostValue = getenv('DB_HOST');
$diagnosticNameValue = getenv('DB_NAME');
$explicitOptIn = getenv('HB_ALLOW_LOCAL_DB_DIAGNOSTICS') === '1';

$diagnosticHost = strtolower(trim((string)($diagnosticHostValue === false ? '' : $diagnosticHostValue), '[]'));
$diagnosticDatabase = strtolower((string)($diagnosticNameValue === false ? '' : $diagnosticNameValue));
$allowedHosts = ['127.0.0.1', 'localhost', '::1'];

if (
    !$explicitOptIn
    || !in_array($diagnosticHost, $allowedHosts, true)
    || !preg_match('/_(test|qa)$/', $diagnosticDatabase)
) {
    fwrite(
        STDERR,
        "Refusing to run PatientPortalService diagnostics. Set HB_ALLOW_LOCAL_DB_DIAGNOSTICS=1, DB_HOST to loopback, and DB_NAME to a database ending in _test or _qa. No database connection was opened.\n"
    );
    exit(2);
}

// Define the validated local connection before db.php can load values from .env.
$portValue = getenv('DB_PORT');
$userValue = getenv('DB_USER');
$passValue = getenv('DB_PASS');

define('DB_HOST', $diagnosticHost);
define('DB_PORT', $portValue === false || $portValue === '' ? '3306' : $portValue);
define('DB_NAME', $diagnosticDatabase);
define('DB_USER', $userValue === false || $userValue === '' ? 'root' : $userValue);
define('DB_PASS', $passValue === false ? '' : $passValue);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../services/PatientPortalService.php';

// Synthetic test DB seed: user ID 2 is Ahmed Hassan.
$testUserId = 2;
$failures = 0;

echo "=== Testing PatientPortalService ===\n\n";

try {
    $db = getDB();
    echo "DB connection: OK\n";

    $portal = new PatientPortalService($db);

    $methods = [
        'getOverview',
        'getHealthSnapshot',
        'getAppointmentTimeline',
        'getMedicalTimeline',
        'getPrescriptions',
        'getMedicalProfile',
        'getNotifications',
        'getFavorites',
        'getHealthInsights',
        'getDownloads',
        'getHealthAlerts',
        'getProfileCompletion',
    ];

    foreach ($methods as $method) {
        try {
            echo "Testing {$method}(...) ... ";
            $result = $portal->{$method}($testUserId);
            echo "OK (" . (is_array($result) ? count($result) . ' keys' : 'scalar') . ")\n";
        } catch (Throwable $e) {
            $failures++;
            echo "FAILED: " . $e->getMessage() . " in " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    echo "\n=== Testing appointment timeline consistency ===\n";
    try {
        $timeline = $portal->getAppointmentTimeline($testUserId);
        $groups = ['upcoming', 'completed', 'cancelled', 'missed', 'rescheduled'];
        $seenAppointmentIds = [];
        $groupedCount = 0;

        foreach ($groups as $group) {
            if (!isset($timeline[$group], $timeline['counts'][$group]) || !is_array($timeline[$group])) {
                throw new RuntimeException("Timeline group {$group} is missing or invalid.");
            }

            if ((int)$timeline['counts'][$group] !== count($timeline[$group])) {
                throw new RuntimeException("Timeline count does not match group {$group}.");
            }

            foreach ($timeline[$group] as $appointment) {
                $appointmentId = filter_var($appointment['id'] ?? null, FILTER_VALIDATE_INT);
                if ($appointmentId === false || $appointmentId < 1) {
                    throw new RuntimeException("Timeline contains an invalid appointment identifier.");
                }

                if (isset($seenAppointmentIds[$appointmentId])) {
                    throw new RuntimeException("An appointment appears in more than one timeline group.");
                }
                $seenAppointmentIds[$appointmentId] = true;

                $hasPrescription = (bool)($appointment['has_prescription'] ?? false);
                $prescriptionId = (int)($appointment['prescription_id'] ?? 0);
                if ($hasPrescription !== ($prescriptionId > 0)) {
                    throw new RuntimeException("Prescription presence and identifier disagree.");
                }

                if (!is_bool($appointment['can_rate'] ?? null)) {
                    throw new RuntimeException("Timeline can_rate value is not boolean.");
                }

                if (
                    $appointment['can_rate']
                    && (
                        ($appointment['status'] ?? null) !== 'Confirmed'
                        || ($appointment['workflow_status'] ?? null) !== 'Completed'
                        || (bool)($appointment['has_rating'] ?? false)
                    )
                ) {
                    throw new RuntimeException("Timeline exposes an invalid rating eligibility state.");
                }
            }

            $groupedCount += count($timeline[$group]);
        }

        if ($groupedCount !== (int)($timeline['counts']['total'] ?? -1)) {
            throw new RuntimeException("Timeline group totals do not match the total appointment count.");
        }

        echo "OK ({$groupedCount} appointments; category and linked-record consistency)\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAILED: " . $e->getMessage() . " in " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }

    echo "\n=== Testing getAll() (full dashboard) ===\n";
    try {
        $allData = $portal->getAll($testUserId);
        echo "getAll() OK - returned " . count($allData) . " top-level keys\n";
        foreach ($allData as $key => $value) {
            $type = is_array($value) ? 'array(' . count($value) . ')' : gettype($value);
            echo "  {$key}: {$type}\n";
        }
    } catch (Throwable $e) {
        $failures++;
        echo "getAll() FAILED: " . $e->getMessage() . " in " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "SETUP FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}

echo "FAILURES={$failures}\n";
exit($failures === 0 ? 0 : 1);
