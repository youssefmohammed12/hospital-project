<?php
declare(strict_types=1);

/**
 * Tests the route-level reschedule schema selector without opening a database.
 * V1 is the safe default; V2 must be explicitly selected by server config.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

require_once __DIR__ . '/../services/RescheduleService.php';

$key = 'HEALTHBRIDGE_RESCHEDULE_SCHEMA';
$hadProcessValue = getenv($key) !== false;
$originalProcessValue = $hadProcessValue ? (string)getenv($key) : null;
$hadEnvValue = array_key_exists($key, $_ENV);
$originalEnvValue = $hadEnvValue ? $_ENV[$key] : null;
$hadServerValue = array_key_exists($key, $_SERVER);
$originalServerValue = $hadServerValue ? $_SERVER[$key] : null;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $label);
    }
    echo "PASS|{$label}\n";
};

try {
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
    $assert(
        RescheduleService::schemaFromEnvironment() === RescheduleService::SCHEMA_V1,
        'default remains V1 when no selector is configured'
    );

    putenv($key . '= V2 ');
    $assert(
        RescheduleService::schemaFromEnvironment() === RescheduleService::SCHEMA_V2,
        'process environment explicitly enables V2'
    );

    $_ENV[$key] = ' v2 ';
    putenv($key . '=v1');
    $assert(
        RescheduleService::schemaFromEnvironment() === RescheduleService::SCHEMA_V2,
        'Dotenv-populated ENV value takes precedence'
    );

    unset($_ENV[$key]);
    $_SERVER[$key] = 'V2';
    $assert(
        RescheduleService::schemaFromEnvironment() === RescheduleService::SCHEMA_V2,
        'server configuration is accepted'
    );

    unset($_SERVER[$key]);
    putenv($key . '=v3');
    $invalidRejected = false;
    try {
        RescheduleService::schemaFromEnvironment();
    } catch (InvalidArgumentException $expected) {
        $invalidRejected = true;
    }
    $assert($invalidRejected, 'unsupported schema values fail closed');
} finally {
    if ($hadProcessValue) {
        putenv($key . '=' . $originalProcessValue);
    } else {
        putenv($key);
    }

    if ($hadEnvValue) {
        $_ENV[$key] = $originalEnvValue;
    } else {
        unset($_ENV[$key]);
    }

    if ($hadServerValue) {
        $_SERVER[$key] = $originalServerValue;
    } else {
        unset($_SERVER[$key]);
    }
}

echo "RESULT=PASS (5 schema selector assertions)\n";
