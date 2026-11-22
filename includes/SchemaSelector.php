<?php
declare(strict_types=1);

/**
 * Explicit per-domain schema selector for HTTP adapters.
 *
 * A missing/blank selector preserves V1. V2 is opt-in per domain and never
 * inferred from database/table presence. Invalid values fail closed.
 */
final class HealthBridgeSchemaSelector
{
    public static function fromEnvironment(string $key, string $default = 'v1'): string
    {
        if (!preg_match('/^HEALTHBRIDGE_[A-Z0-9_]+_SCHEMA$/', $key)) {
            throw new InvalidArgumentException('Invalid HealthBridge schema selector key.');
        }
        if (!in_array($default, ['v1', 'v2'], true)) {
            throw new InvalidArgumentException('Invalid default HealthBridge schema.');
        }

        $configured = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($configured === false || $configured === null || trim((string)$configured) === '') {
            return $default;
        }

        $schema = strtolower(trim((string)$configured));
        if (!in_array($schema, ['v1', 'v2'], true)) {
            throw new InvalidArgumentException($key . ' must be v1 or v2.');
        }

        return $schema;
    }
}
