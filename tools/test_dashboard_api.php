<?php
/**
 * CLI test script for PatientPortalService
 * Run: php tools/test_dashboard_api.php
 * Tests each method in isolation to find the exact crash point.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../services/PatientPortalService.php';

// Get the demo patient (user ID 2 = "Ahmed Hassan")
$testUserId = 2;

echo "=== Testing PatientPortalService ===\n\n";

try {
    $db = getDB();
    echo "DB connection: OK\n";
    
    $portal = new PatientPortalService($db);
    
    // Test each method individually
    $methods = [
        'getOverview' => null,
        'getHealthSnapshot' => null,
        'getAppointmentTimeline' => null,
        'getMedicalTimeline' => null,
        'getPrescriptions' => null,
        'getMedicalProfile' => null,
        'getNotifications' => null,
        'getFavorites' => null,
        'getHealthInsights' => null,
        'getDownloads' => null,
        'getHealthAlerts' => null,
        'getProfileCompletion' => null,
    ];
    
    foreach ($methods as $method => &$_) {
        try {
            echo "Testing $method(...) ... ";
            $result = $portal->$method($testUserId);
            echo "OK (" . (is_array($result) ? count($result) . ' keys' : 'scalar') . ")\n";
        } catch (Throwable $e) {
            echo "FAILED: " . $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
        }
    }
    
    echo "\n=== Testing getAll() (full dashboard) ===\n";
    try {
        $allData = $portal->getAll($testUserId);
        echo "getAll() OK - returned " . count($allData) . " top-level keys\n";
        foreach ($allData as $key => $val) {
            $type = is_array($val) ? 'array(' . count($val) . ')' : gettype($val);
            echo "  $key: $type\n";
        }
    } catch (Throwable $e) {
        echo "getAll() FAILED: " . $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
    }
    
} catch (Throwable $e) {
    echo "SETUP FAILED: " . $e->getMessage() . "\n";
    echo "File: " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
}