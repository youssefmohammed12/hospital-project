<?php
declare(strict_types=1);

/**
 * CLI-only sender for the registration queue age alert.
 *
 * Arguments contain only a constrained event name, an integer age and an
 * ISO timestamp. No patient/account data or credentials are passed here.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../services/HealthBridgeMailService.php';

$values = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(event|age-seconds|first-seen-at)=(.*)$/', $argument, $matches)) {
        if (array_key_exists($matches[1], $values)) {
            fwrite(STDERR, "Duplicate argument.\n");
            exit(2);
        }
        $values[$matches[1]] = $matches[2];
    } else {
        fwrite(STDERR, "Unsupported argument.\n");
        exit(2);
    }
}

if (
    count($values) !== 3
    || !in_array($values['event'] ?? '', ['OPEN', 'REMINDER', 'RESOLVED'], true)
    || !preg_match('/^\d{1,10}$/', $values['age-seconds'] ?? '')
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $values['first-seen-at'] ?? '')
) {
    fwrite(STDERR, "Invalid queue alert arguments.\n");
    exit(2);
}

try {
    HealthBridgeMailService::sendQueueAgeAlert(
        $values['event'],
        (int)$values['age-seconds'],
        $values['first-seen-at']
    );
    printf("EMAIL_SENT=1 EVENT=%s\n", $values['event']);
    exit(0);
} catch (Throwable $error) {
    // Never print provider exception details or any message content.
    fwrite(STDERR, "EMAIL_SENT=0 EVENT=" . $values['event'] . " ERROR=" . get_class($error) . "\n");
    exit(1);
}
