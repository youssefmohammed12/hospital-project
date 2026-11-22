<?php
/**
 * HealthBridge — authentication abuse throttling.
 *
 * Registration and login attempt state plus the HMAC key are stored in the
 * host temporary directory, outside the web root. Identifiers are never
 * written in plaintext. This is single-host storage; multi-host deployments
 * should replace it with a shared Redis/database limiter.
 */
final class AuthAbuseRateLimiter
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $applicationRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        $applicationFingerprint = substr(hash('sha256', $applicationRoot), 0, 16);
        $this->directory = $directory
            ?? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'healthbridge-auth-limits-' . $applicationFingerprint;
    }

    /**
     * Record an attempt and return the required Retry-After seconds (0 if allowed).
     *
     * IP windows: 20 step-1 validations / 10 minutes; 10 step-2 submissions / hour.
     * Identifier windows: 5 step-1 attempts / hour; 3 step-2 attempts / hour.
     * Email, phone, and National ID are separate identifiers so changing one
     * submitted field cannot reset the limit for another.
     */
    public function recordRegistrationAttempt(array $input, int $step, string $remoteAddress): int
    {
        if (!in_array($step, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported registration step.');
        }

        $secret = $this->loadSecret();
        $now = time();
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? strtolower($remoteAddress)
            : 'unknown';

        $requests = [];
        $requests[] = [
            'key' => 'ip-step-' . $step . ':' . hash_hmac('sha256', $ip, $secret),
            'window_seconds' => $step === 1 ? 600 : 3600,
            'limit' => $step === 1 ? 20 : 10,
        ];

        foreach (['email', 'phone', 'national_id'] as $field) {
            $value = $input[$field] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '' || mb_strlen($value) > 256) {
                continue;
            }

            if ($field === 'email') {
                $value = strtolower($value);
            } elseif ($field === 'phone') {
                $value = preg_replace('/[\\s().-]+/', '', $value) ?? '';
                if (str_starts_with($value, '+20')) {
                    $value = '0' . substr($value, 3);
                }
            } else {
                $value = preg_replace('/\\s+/', '', $value) ?? '';
            }

            if ($value === '') {
                continue;
            }

            $requests[] = [
                'key' => 'identity-' . $field . ':' . hash_hmac('sha256', $value, $secret),
                'window_seconds' => 3600,
                'limit' => $step === 1 ? 5 : 3,
            ];
        }

        return $this->updateState($requests, $now);
    }

    /**
     * Count a sign-in request by source IP and normalized email.
     * Successful requests also count, so the limits are intentionally generous.
     * 60 attempts per IP and 10 per email are allowed in a 15-minute window.
     */
    public function recordLoginAttempt(string $email, string $remoteAddress): int
    {
        $secret = $this->loadSecret();
        $now = time();
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? strtolower($remoteAddress)
            : 'unknown';
        $normalizedEmail = strtolower(trim($email));

        $requests = [
            [
                'key' => 'login-ip:' . hash_hmac('sha256', $ip, $secret),
                'window_seconds' => 900,
                'limit' => 60,
            ],
            [
                'key' => 'login-email:' . hash_hmac('sha256', $normalizedEmail, $secret),
                'window_seconds' => 900,
                'limit' => 10,
            ],
        ];

        return $this->updateState($requests, $now);
    }

    /**
     * Limit registration-verification resends without storing submitted email
     * addresses. Three attempts per email and ten per IP are allowed per hour.
     */
    public function recordRegistrationVerificationResend(string $email, string $remoteAddress): int
    {
        $secret = $this->loadSecret();
        $now = time();
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? strtolower($remoteAddress)
            : 'unknown';
        $normalizedEmail = strtolower(trim($email));

        return $this->updateState([
            [
                'key' => 'registration-verify-resend-ip:' . hash_hmac('sha256', $ip, $secret),
                'window_seconds' => 3600,
                'limit' => 10,
            ],
            [
                'key' => 'registration-verify-resend-email:' . hash_hmac('sha256', $normalizedEmail, $secret),
                'window_seconds' => 3600,
                'limit' => 3,
            ],
        ], $now);
    }

    /**
     * Limit confirmation attempts by both source IP and the submitted token
     * hash. The raw bearer token is never written into limiter state.
     */
    public function recordRegistrationVerificationConfirmation(string $tokenHash, string $remoteAddress): int
    {
        $secret = $this->loadSecret();
        $now = time();
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? strtolower($remoteAddress)
            : 'unknown';

        return $this->updateState([
            [
                'key' => 'registration-verify-confirm-ip:' . hash_hmac('sha256', $ip, $secret),
                'window_seconds' => 900,
                'limit' => 30,
            ],
            [
                'key' => 'registration-verify-confirm-token:' . hash_hmac('sha256', $tokenHash, $secret),
                'window_seconds' => 900,
                'limit' => 10,
            ],
        ], $now);
    }

    private function loadSecret(): string
    {
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'hmac-secret.key';
        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Authentication rate limiter key is unavailable.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Authentication rate limiter key cannot be locked.');
            }

            rewind($handle);
            $secret = stream_get_contents($handle);
            if ($secret === false) {
                throw new RuntimeException('Authentication rate limiter key cannot be read.');
            }

            if (strlen($secret) < 32) {
                $secret = random_bytes(32);
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $secret) !== strlen($secret) || !fflush($handle)) {
                    throw new RuntimeException('Authentication rate limiter key cannot be stored.');
                }
                @chmod($path, 0600);
            }

            return $secret;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function updateState(array $requests, int $now): int
    {
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'attempts.json';
        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Authentication rate limiter state is unavailable.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Authentication rate limiter state cannot be locked.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            if ($raw === false) {
                throw new RuntimeException('Authentication rate limiter state cannot be read.');
            }

            $state = ['entries' => []];
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
                    throw new RuntimeException('Authentication rate limiter state is invalid.');
                }
                $state = $decoded;
            }

            $entries = $state['entries'];
            foreach ($entries as $key => $entry) {
                if (!is_array($entry)) {
                    unset($entries[$key]);
                    continue;
                }

                $windowStarted = (int)($entry['window_started'] ?? 0);
                $windowSeconds = (int)($entry['window_seconds'] ?? 0);
                if ($windowStarted <= 0 || $windowSeconds <= 0 || ($windowStarted + $windowSeconds) <= $now) {
                    unset($entries[$key]);
                }
            }

            $retryAfter = 0;
            foreach ($requests as $request) {
                $key = $request['key'];
                $windowSeconds = (int)$request['window_seconds'];
                $limit = (int)$request['limit'];
                $entry = $entries[$key] ?? null;

                if (
                    !is_array($entry)
                    || (int)($entry['window_started'] ?? 0) + $windowSeconds <= $now
                ) {
                    $entry = [
                        'window_started' => $now,
                        'window_seconds' => $windowSeconds,
                        'count' => 0,
                    ];
                }

                $entry['count'] = (int)$entry['count'] + 1;
                $entry['window_seconds'] = $windowSeconds;
                $entries[$key] = $entry;

                if ($entry['count'] > $limit) {
                    $remaining = ((int)$entry['window_started'] + $windowSeconds) - $now;
                    $retryAfter = max($retryAfter, max(1, $remaining));
                }
            }

            // Bound disk use even during a burst of distinct, one-off identifiers.
            if (count($entries) > 10000) {
                uasort(
                    $entries,
                    static fn(array $a, array $b): int =>
                        ((int)($a['window_started'] ?? 0)) <=> ((int)($b['window_started'] ?? 0))
                );
                $entries = array_slice($entries, -10000, null, true);
            }

            $serialized = json_encode(['entries' => $entries], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $serialized) !== strlen($serialized) || !fflush($handle)) {
                throw new RuntimeException('Authentication rate limiter state cannot be stored.');
            }
            @chmod($path, 0600);

            return $retryAfter;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Authentication rate limiter directory cannot be created.');
            }
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException('Authentication rate limiter directory is not writable.');
        }
    }
}
