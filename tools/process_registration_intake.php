<?php
declare(strict_types=1);

/**
 * HealthBridge encrypted registration-intake worker.
 *
 * CLI only:
 *   php tools/process_registration_intake.php
 *   php tools/process_registration_intake.php --limit=100
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../services/RegistrationIntakeService.php';

$limit = 50;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = (int)$matches[1];
    } else {
        fwrite(STDERR, "Unsupported argument. Use --limit=1..100.\n");
        exit(2);
    }
}

if ($limit < 1 || $limit > 100) {
    fwrite(STDERR, "Limit must be between 1 and 100.\n");
    exit(2);
}

try {
    // V2-only deployment: RegistrationIntakeService finalizes registrations
    // against the V2 tables (V1 schema selection retired in Batch 2 Stage B).
    $worker = new RegistrationIntakeService(
        getDB()
    );
    $stats = $worker->processBatch($limit);
    $stats['queue_age_warning'] = $stats['oldest_pending_age_seconds'] > 120;

    echo json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($stats['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    $diagnostic = get_class($error);
    if ($error instanceof PDOException) {
        $rawSqlState = $error->errorInfo[0] ?? $error->getCode();
        $sqlState = preg_replace('/[^A-Za-z0-9]/', '', (string)$rawSqlState) ?: 'unknown';
        $rawDriverCode = $error->errorInfo[1] ?? null;
        $driverCode = is_numeric($rawDriverCode) ? (string)(int)$rawDriverCode : 'unknown';
        $diagnostic .= ' sqlstate=' . $sqlState . ' driver_code=' . $driverCode;
    }

    fwrite(STDERR, 'Registration intake worker failed: ' . $diagnostic . PHP_EOL);
    exit(1);
}
