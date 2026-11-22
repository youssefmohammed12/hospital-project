<?php
/**
 * HealthBridge durable notification outbox worker.
 *
 * Run from the project root:
 *   php tools/process_notification_outbox.php
 *   php tools/process_notification_outbox.php --limit=100
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../services/NotificationService.php';

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
    $service = new NotificationService(getDB());
    $stats = $service->processOutbox($limit);

    printf(
        "delivered=%d skipped=%d retried=%d terminal_failed=%d pending=%d\n",
        $stats['delivered'],
        $stats['skipped'],
        $stats['retried'],
        $stats['failed'],
        $stats['remaining']
    );

    exit($stats['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    // Record safe connection/driver classification codes for operations. Never
    // log the exception message or DSN, which may contain sensitive details.
    $diagnostic = get_class($error);
    if ($error instanceof PDOException) {
        $rawSqlState = $error->errorInfo[0] ?? $error->getCode();
        $sqlState = preg_replace('/[^A-Za-z0-9]/', '', (string)$rawSqlState) ?: 'unknown';
        $rawDriverCode = $error->errorInfo[1] ?? null;
        $driverCode = is_numeric($rawDriverCode) ? (string)(int)$rawDriverCode : 'unknown';
        $diagnostic .= ' sqlstate=' . $sqlState . ' driver_code=' . $driverCode;
    }

    fwrite(STDERR, 'Notification outbox worker failed: ' . $diagnostic . "\n");
    exit(1);
}