<?php
declare(strict_types=1);

/**
 * Sends one explicit SMTP smoke-test message to the fixed HealthBridge
 * operations mailbox. CLI only; it accepts no recipient or credential args.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../services/HealthBridgeMailService.php';

try {
    HealthBridgeMailService::send(
        HealthBridgeMailService::OPERATIONS_ADDRESS,
        'HealthBridge Gmail SMTP test',
        "This is a test message from HealthBridge.\n\n"
            . "The dedicated Gmail SMTP transport successfully sent this message.\n"
            . "No patient or account data is included."
    );
    fwrite(STDOUT, "EMAIL_TEST_SENT=1 RECIPIENT=OPERATIONS_MAILBOX\n");
    exit(0);
} catch (Throwable $error) {
    // Do not display provider diagnostics, which may contain private metadata.
    fwrite(STDERR, "EMAIL_TEST_SENT=0 ERROR=" . get_class($error) . "\n");
    exit(1);
}
