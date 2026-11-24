<?php
declare(strict_types=1);

/**
 * Verify that PHP and Apache emit the same application-wide Content Security Policy.
 *
 * This is a read-only configuration check; it does not connect to the database.
 */

require_once __DIR__ . '/../../includes/response.php';

$htaccessPath = __DIR__ . '/../../.htaccess';
$htaccess = file_get_contents($htaccessPath);

if ($htaccess === false) {
    fwrite(STDERR, "CSP_POLICY_SYNC=FAIL: .htaccess is missing or unreadable.\n");
    exit(1);
}

$pattern = '/^\s*Header always set Content-Security-Policy "([^"]+)" "expr=-z %\{resp:Content-Security-Policy\}"\s*$/m';
if (preg_match($pattern, $htaccess, $matches) !== 1) {
    fwrite(STDERR, "CSP_POLICY_SYNC=FAIL: expected one Apache fallback policy rule was not found.\n");
    exit(1);
}

$apachePolicy = $matches[1];
$phpPolicy = HEALTHBRIDGE_CONTENT_SECURITY_POLICY;

if ($apachePolicy !== $phpPolicy) {
    fwrite(STDERR, "CSP_POLICY_SYNC=FAIL: PHP and Apache policy definitions differ.\n");
    exit(1);
}

$requiredDirectives = [
    "default-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "script-src-attr 'none'",
    "style-src-attr 'none'",
];

foreach ($requiredDirectives as $directive) {
    if (!str_contains($phpPolicy, $directive)) {
        fwrite(STDERR, "CSP_POLICY_SYNC=FAIL: missing required directive: {$directive}\n");
        exit(1);
    }
}

echo "CSP_POLICY_SYNC=PASS\n";
echo "PHP_AND_APACHE_POLICY=IDENTICAL\n";
echo "REQUIRED_DIRECTIVES=" . count($requiredDirectives) . "\n";
