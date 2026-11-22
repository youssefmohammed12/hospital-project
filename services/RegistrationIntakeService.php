<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/RegistrationProfileValidator.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/HealthBridgeMailService.php';

/**
 * Durable encrypted registration intake.
 *
 * The web request validates, hashes, encrypts and inserts one queue row.
 * This service's CLI worker performs uniqueness checks and account creation.
 * PII and password hashes are never written to queue columns in plaintext.
 */
final class RegistrationIntakeService
{
    private const AAD = 'healthbridge.registration-intake.v1';
    private const PAYLOAD_VERSION = 1;
    private const MAX_ATTEMPTS = 5;
    private const LEASE_MINUTES = 5;

    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    /** @var array<string,string> */
    private array $keyRing;

    private string $activeKeyVersion;

    public function __construct(PDO $db, string $schema = self::SCHEMA_V1)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('RegistrationIntakeService schema must be explicitly v1 or v2.');
        }
        if (!extension_loaded('openssl') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('Authenticated encryption support is unavailable.');
        }

        $this->db = $db;
        $this->schema = $schema;
        [$this->keyRing, $this->activeKeyVersion] = self::loadKeyRing();
    }

    /**
     * Persist a registration profile as one encrypted queue record.
     * The caller supplies a password_hash, never a plaintext password.
     */
    public function enqueue(array $profile): string
    {
        $normalizedEmail = strtolower(trim((string)($profile['email'] ?? '')));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Registration email is invalid.');
        }
        $payload = json_encode(
            ['profile' => $profile],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $nonce = random_bytes(12);
        $tag = '';
        $key = $this->keyRing[$this->activeKeyVersion];
        $ciphertext = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            16
        );
        unset($payload, $profile);

        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Registration payload encryption failed.');
        }

        $requestId = bin2hex(random_bytes(16));
        $emailLookupHmac = $this->emailLookupHmac($normalizedEmail, $this->activeKeyVersion);
        unset($normalizedEmail);
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO registration_intake_queue
                    (request_id, payload_version, key_version, nonce, auth_tag, ciphertext,
                     email_lookup_hmac, status, attempt_count, available_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())"
            );
            $stmt->execute([
                $requestId,
                self::PAYLOAD_VERSION,
                $this->activeKeyVersion,
                $nonce,
                $tag,
                $ciphertext,
                $emailLookupHmac,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            unset($ciphertext, $nonce, $tag, $emailLookupHmac);
        }

        return $requestId;
    }

    /**
     * Process a bounded batch. A local file lock prevents accidental overlapping
     * scheduled invocations on this Windows host; database leases recover a
     * job if the process is interrupted.
     *
     * @return array<string,int>
     */
    public function processBatch(int $limit = 50): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('The registration queue worker is CLI-only.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Worker batch limit must be between 1 and 100.');
        }

        $lockPath = self::workerLockPath();
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the registration worker lock.');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another registration intake worker is already active.');
        }

        $stats = [
            'claimed' => 0,
            'awaiting_verification' => 0,
            'completed' => 0,
            'duplicate' => 0,
            'failed' => 0,
            'retried' => 0,
            'notifications_delivered' => 0,
            'notifications_skipped' => 0,
            'verification_emails_sent' => 0,
            'verification_email_failures' => 0,
            'expired' => 0,
            'awaiting_verification_count' => 0,
            'email_verification_schema_ready' => 0,
            'oldest_pending_age_seconds' => 0,
        ];

        try {
            // Keep the live Phase 16 installation operational before the
            // Phase 17 migration is approved/applied. Do not process old
            // registrations without the email-verification state machine.
            if (!$this->hasEmailVerificationSchema()) {
                $age = $this->db->query(
                    "SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0)
                     FROM registration_intake_queue
                     WHERE status IN ('pending', 'processing')"
                );
                $stats['oldest_pending_age_seconds'] = max(0, (int)$age->fetchColumn());
                return $stats;
            }
            $stats['email_verification_schema_ready'] = 1;

            // Expire old unverified registrations in a bounded transaction before
            // claiming ordinary worker work. Awaiting-user jobs are not backlog.
            $stats['expired'] = $this->expireAwaitingVerificationJobs($limit);
            // Count terminalized/expired jobs against the batch bound.
            $processed = $stats['expired'];
            while ($processed < $limit) {
                $job = $this->claimNextJob();
                if ($job === null) {
                    break;
                }
                $processed++;

                if (!empty($job['_terminalized_failed'])) {
                    // The claim path already locked and terminalized this row.
                    $stats['failed']++;
                    continue;
                }

                $stats['claimed']++;
                $outcome = $this->processClaimedJob($job);
                if (isset($stats[$outcome['status']])) {
                    $stats[$outcome['status']]++;
                }
                $stats['verification_emails_sent'] += (int)($outcome['verification_email_sent'] ?? 0);
                $stats['verification_email_failures'] += (int)($outcome['verification_email_failed'] ?? 0);

                foreach (($outcome['notification_stats'] ?? []) as $key => $value) {
                    if ($key === 'delivered') {
                        $stats['notifications_delivered'] += (int)$value;
                    } elseif ($key === 'skipped') {
                        $stats['notifications_skipped'] += (int)$value;
                    }
                }
            }

            $age = $this->db->query(
                "SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0)
                 FROM registration_intake_queue
                 WHERE status IN ('pending', 'processing')"
            );
            $stats['oldest_pending_age_seconds'] = max(0, (int)$age->fetchColumn());
            $awaiting = $this->db->query(
                "SELECT COUNT(*) FROM registration_intake_queue WHERE status = 'awaiting_verification'"
            );
            $stats['awaiting_verification_count'] = (int)$awaiting->fetchColumn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    /**
     * Return true only when every Phase 17 field/table required by the
     * verification-first workflow is present in the currently connected schema.
     * This is intentionally read-only and keeps the worker safe on Phase 16
     * installs while a migration is still pending.
     */
    private function hasEmailVerificationSchema(): bool
    {
        $stmt = $this->db->query(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'users'
                      AND COLUMN_NAME IN ('email_verification_required', 'email_verified_at')
                ) AS user_columns,
                (
                    SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'registration_intake_queue'
                      AND COLUMN_NAME IN ('email_lookup_hmac', 'verification_expires_at', 'email_verified_at')
                ) AS queue_columns,
                (
                    SELECT COUNT(*)
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'registration_email_challenges'
                      AND TABLE_TYPE = 'BASE TABLE'
                ) AS challenge_table"
        );
        $schema = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($schema['user_columns'] ?? 0) === 2
            && (int)($schema['queue_columns'] ?? 0) === 3
            && (int)($schema['challenge_table'] ?? 0) === 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function claimNextJob(): ?array
    {
        while (true) {
            $this->db->beginTransaction();

            try {
                $stmt = $this->db->query(
                    "SELECT id, payload_version, key_version, nonce, auth_tag, ciphertext,
                            attempt_count, status, email_verified_at
                     FROM registration_intake_queue
                     WHERE (status = 'pending' AND available_at <= NOW())
                        OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW())
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE"
                );
                $job = $stmt->fetch();

                if (!$job) {
                    $this->db->commit();
                    return null;
                }

                // An abrupt process exit does not reach retryOrFail(). Once
                // attempt five's lease expires, terminalize it under this same
                // row lock instead of allowing an unbounded sixth claim.
                if (
                    (string)$job['status'] === 'processing'
                    && (int)$job['attempt_count'] >= self::MAX_ATTEMPTS
                ) {
                    $terminal = $this->db->prepare(
                        "UPDATE registration_intake_queue
                         SET status = 'failed',
                             nonce = NULL,
                             auth_tag = NULL,
                             ciphertext = NULL,
                             email_lookup_hmac = NULL,
                             verification_expires_at = NULL,
                             lease_expires_at = NULL,
                             worker_token = NULL,
                             processed_at = NOW(),
                             last_error_code = 'crash_recovery_attempt_limit_exceeded'
                         WHERE id = ?
                           AND status = 'processing'
                           AND attempt_count >= ?
                           AND lease_expires_at IS NOT NULL
                           AND lease_expires_at < NOW()"
                    );
                    $terminal->execute([(int)$job['id'], self::MAX_ATTEMPTS]);
                    if ($terminal->rowCount() !== 1) {
                        throw new RuntimeException('Exhausted registration lease could not be terminalized.');
                    }

                    $this->db->commit();
                    return ['_terminalized_failed' => true];
                }

                $token = bin2hex(random_bytes(16));
                $update = $this->db->prepare(
                    "UPDATE registration_intake_queue
                     SET status = 'processing',
                         attempt_count = attempt_count + 1,
                         started_at = NOW(),
                         lease_expires_at = DATE_ADD(NOW(), INTERVAL " . self::LEASE_MINUTES . " MINUTE),
                         worker_token = ?,
                         last_error_code = NULL
                     WHERE id = ?"
                );
                $update->execute([$token, (int)$job['id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Could not claim the registration queue job.');
                }

                $this->db->commit();
                $job['worker_token'] = $token;
                $job['attempt_count'] = (int)$job['attempt_count'] + 1;
                return $job;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }
    }

    /**
     * @param array<string,mixed> $job
     * @return array{status:string,notification_stats?:array<string,int>}
     */
    private function processClaimedJob(array $job): array
    {
        $id = (int)$job['id'];
        $token = (string)$job['worker_token'];
        $payloadVersion = (int)$job['payload_version'];
        $keyVersion = (string)$job['key_version'];

        if ($payloadVersion !== self::PAYLOAD_VERSION || !isset($this->keyRing[$keyVersion])) {
            $this->markTerminal($id, $token, 'failed', 'unsupported_payload_or_key_version');
            return ['status' => 'failed'];
        }

        $plain = openssl_decrypt(
            (string)$job['ciphertext'],
            'aes-256-gcm',
            $this->keyRing[$keyVersion],
            OPENSSL_RAW_DATA,
            (string)$job['nonce'],
            (string)$job['auth_tag'],
            self::AAD
        );

        if (!is_string($plain)) {
            $this->markTerminal($id, $token, 'failed', 'authenticated_decryption_failed');
            return ['status' => 'failed'];
        }

        try {
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            unset($plain);
            $this->markTerminal($id, $token, 'failed', 'invalid_encrypted_payload_json');
            return ['status' => 'failed'];
        }
        unset($plain);

        if (!is_array($decoded) || !isset($decoded['profile']) || !is_array($decoded['profile'])) {
            $this->markTerminal($id, $token, 'failed', 'invalid_encrypted_payload_shape');
            return ['status' => 'failed'];
        }

        $validated = RegistrationProfileValidator::validateQueued($decoded['profile']);
        unset($decoded);

        if ($validated['errors'] !== []) {
            unset($validated);
            $this->markTerminal($id, $token, 'failed', 'queued_profile_validation_failed');
            return ['status' => 'failed'];
        }

        $profile = $validated['data'];
        unset($validated);

        try {
            $this->db->beginTransaction();

            $jobLock = $this->db->prepare(
                "SELECT id
                 FROM registration_intake_queue
                 WHERE id = ? AND status = 'processing' AND worker_token = ?
                 FOR UPDATE"
            );
            $jobLock->execute([$id, $token]);
            if (!$jobLock->fetchColumn()) {
                $this->db->rollBack();
                unset($profile);
                return ['status' => 'retried'];
            }

            if ($this->hasIdentityConflict($profile)) {
                $this->completeQueueJobWithinTransaction($id, $token, 'duplicate');
                $this->db->commit();
                unset($profile);
                return ['status' => 'duplicate'];
            }

            if (empty($job['email_verified_at'])) {
                $recipient = (string)$profile['email'];
                $challenge = $this->createRegistrationVerificationChallengeWithinTransaction(
                    $id,
                    $token,
                    $keyVersion,
                    $recipient
                );
                $this->db->commit();
                unset($profile);

                $emailSent = $this->sendVerificationChallenge(
                    $id,
                    (string)$challenge['token_hash'],
                    $recipient,
                    (string)$challenge['token']
                );
                return [
                    'status' => 'awaiting_verification',
                    'verification_email_sent' => $emailSent ? 1 : 0,
                    'verification_email_failed' => $emailSent ? 0 : 1,
                ];
            }

            [$userId, $outboxIds] = $this->createPatientWithinTransaction(
                $profile,
                (string)$job['email_verified_at']
            );
            $this->completeQueueJobWithinTransaction($id, $token, 'completed');
            $this->db->commit();
            unset($profile);

            $notificationStats = $this->dispatchRegistrationNotifications($outboxIds);
            return ['status' => 'completed', 'notification_stats' => $notificationStats];
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            unset($profile);

            if (self::isIdentityUniqueViolation($error)) {
                $this->markTerminal($id, $token, 'duplicate', '');
                return ['status' => 'duplicate'];
            }

            $this->retryOrFail($id, $token, (int)$job['attempt_count'], self::safeErrorCode($error));
            return ['status' => (int)$job['attempt_count'] >= self::MAX_ATTEMPTS ? 'failed' : 'retried'];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            unset($profile);

            error_log('Registration intake worker operation failed: ' . get_class($error));
            $this->retryOrFail($id, $token, (int)$job['attempt_count'], 'worker_operation_failed');
            return ['status' => (int)$job['attempt_count'] >= self::MAX_ATTEMPTS ? 'failed' : 'retried'];
        }
    }

    /**
     * Derive a purpose-specific lookup key from the versioned registration
     * encryption key. The raw AES key is never reused directly as an HMAC key.
     */
    private function emailLookupHmac(string $email, string $keyVersion): string
    {
        if (!isset($this->keyRing[$keyVersion])) {
            throw new RuntimeException('Registration email lookup key version is unavailable.');
        }

        $normalizedEmail = strtolower(trim($email));
        $derivedKey = hash_hkdf(
            'sha256',
            $this->keyRing[$keyVersion],
            32,
            'healthbridge.registration.email-lookup.v1',
            'HealthBridge'
        );
        return hash_hmac('sha256', $normalizedEmail, $derivedKey, true);
    }

    /**
     * Create an initial email challenge while the queue row is already locked
     * by the caller. The raw token is returned only to the caller's memory.
     *
     * @return array{token:string,token_hash:string}
     */
    private function createRegistrationVerificationChallengeWithinTransaction(
        int $queueId,
        string $workerToken,
        string $keyVersion,
        string $email
    ): array {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $emailLookupHmac = $this->emailLookupHmac($email, $keyVersion);

        // Calculate the 24-hour deadline once so the queue and its initial
        // challenge share the exact same timestamp (resends reuse this value).
        $verificationDeadline = $this->db
            ->query("SELECT DATE_ADD(NOW(), INTERVAL 24 HOUR)")
            ->fetchColumn();
        if (!is_string($verificationDeadline) || $verificationDeadline === '') {
            throw new RuntimeException('Registration verification deadline could not be created.');
        }

        $challenge = $this->db->prepare(
            "INSERT INTO registration_email_challenges
                (queue_id, token_hash, created_at, expires_at, send_attempt_count, last_send_attempt_at)
             VALUES (?, ?, NOW(), ?, 1, NOW())"
        );
        $challenge->execute([$queueId, $tokenHash, $verificationDeadline]);

        $queue = $this->db->prepare(
            "UPDATE registration_intake_queue
             SET status = 'awaiting_verification',
                 email_lookup_hmac = ?,
                 verification_expires_at = ?,
                 email_verified_at = NULL,
                 lease_expires_at = NULL,
                 worker_token = NULL,
                 processed_at = NULL,
                 last_error_code = NULL
             WHERE id = ? AND status = 'processing' AND worker_token = ?"
        );
        $queue->execute([$emailLookupHmac, $verificationDeadline, $queueId, $workerToken]);
        if ($queue->rowCount() !== 1) {
            throw new RuntimeException('Registration verification queue transition was not owned by this worker.');
        }

        return ['token' => $token, 'token_hash' => $tokenHash];
    }

    /**
     * Send after the challenge/queue transaction has committed. A failed SMTP
     * attempt leaves the encrypted pending job available for a safe resend.
     */
    private function sendVerificationChallenge(
        int $queueId,
        string $tokenHash,
        string $recipient,
        string $token
    ): bool {
        $success = false;
        try {
            HealthBridgeMailService::sendRegistrationVerification($recipient, $token);
            $success = true;
        } catch (Throwable $error) {
            error_log('Registration verification email delivery failed: ' . get_class($error));
        } finally {
            unset($token);
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE registration_email_challenges
                 SET last_sent_at = CASE WHEN ? = 1 THEN NOW() ELSE last_sent_at END,
                     last_send_failure_at = CASE WHEN ? = 1 THEN last_send_failure_at ELSE NOW() END
                 WHERE queue_id = ? AND token_hash = ? AND revoked_at IS NULL"
            );
            $flag = $success ? 1 : 0;
            $stmt->execute([$flag, $flag, $queueId, $tokenHash]);
        } catch (Throwable $error) {
            error_log('Registration verification delivery status update failed: ' . get_class($error));
        }

        return $success;
    }

    /**
     * Resend only for an eligible encrypted registration. The HMAC lookup
     * selects a candidate; the ciphertext is then authenticated/decrypted and
     * the normalized email is checked again before a new challenge is issued.
     * The caller must always return the same public response.
     */
    public function resendVerificationEmail(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        foreach ($this->keyRing as $keyVersion => $key) {
            unset($key);
            $lookupHmac = $this->emailLookupHmac($normalizedEmail, (string)$keyVersion);
            $recipient = null;
            $queueId = null;
            $token = null;
            $tokenHash = null;

            $this->db->beginTransaction();
            try {
                $candidate = $this->db->prepare(
                    "SELECT id, payload_version, key_version, nonce, auth_tag, ciphertext,
                            verification_expires_at
                     FROM registration_intake_queue
                     WHERE status = 'awaiting_verification'
                       AND key_version = ?
                       AND email_lookup_hmac = ?
                       AND verification_expires_at > NOW()
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE"
                );
                $candidate->execute([(string)$keyVersion, $lookupHmac]);
                $job = $candidate->fetch();

                if (!$job || (int)$job['payload_version'] !== self::PAYLOAD_VERSION) {
                    $this->db->commit();
                    continue;
                }

                if (!isset($this->keyRing[(string)$job['key_version']])) {
                    $this->db->commit();
                    continue;
                }

                $plain = openssl_decrypt(
                    (string)$job['ciphertext'],
                    'aes-256-gcm',
                    $this->keyRing[(string)$job['key_version']],
                    OPENSSL_RAW_DATA,
                    (string)$job['nonce'],
                    (string)$job['auth_tag'],
                    self::AAD
                );
                if (!is_string($plain)) {
                    $this->db->commit();
                    continue;
                }

                try {
                    $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable $error) {
                    unset($plain);
                    $this->db->commit();
                    continue;
                }
                unset($plain);

                $queuedEmail = strtolower(trim((string)($decoded['profile']['email'] ?? '')));
                unset($decoded);
                if (!hash_equals($normalizedEmail, $queuedEmail)) {
                    $this->db->commit();
                    continue;
                }

                $queueId = (int)$job['id'];
                $recipient = $queuedEmail;
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $revoke = $this->db->prepare(
                    "UPDATE registration_email_challenges
                     SET revoked_at = NOW()
                     WHERE queue_id = ?
                       AND consumed_at IS NULL
                       AND revoked_at IS NULL"
                );
                $revoke->execute([$queueId]);

                $challenge = $this->db->prepare(
                    "INSERT INTO registration_email_challenges
                        (queue_id, token_hash, created_at, expires_at, send_attempt_count, last_send_attempt_at)
                     VALUES (?, ?, NOW(), ?, 1, NOW())"
                );
                $challenge->execute([
                    $queueId,
                    $tokenHash,
                    (string)$job['verification_expires_at'],
                ]);
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            if ($queueId !== null && $recipient !== null && $token !== null && $tokenHash !== null) {
                $this->sendVerificationChallenge($queueId, $tokenHash, $recipient, $token);
                return;
            }
        }
    }

    /**
     * Confirm a bearer token exactly once. Lock order is always queue then
     * challenge, matching resend and expiry operations.
     */
    public function confirmRegistrationEmail(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return false;
        }

        $tokenHash = hash('sha256', strtolower($token));
        $lookup = $this->db->prepare(
            'SELECT id, queue_id
             FROM registration_email_challenges
             WHERE token_hash = ?
             LIMIT 1'
        );
        $lookup->execute([$tokenHash]);
        $candidate = $lookup->fetch();
        if (!$candidate) {
            return false;
        }

        $queueId = (int)$candidate['queue_id'];
        $challengeId = (int)$candidate['id'];
        $this->db->beginTransaction();

        try {
            $queueLock = $this->db->prepare(
                "SELECT id
                 FROM registration_intake_queue
                 WHERE id = ?
                   AND status = 'awaiting_verification'
                   AND verification_expires_at > NOW()
                 FOR UPDATE"
            );
            $queueLock->execute([$queueId]);
            if (!$queueLock->fetchColumn()) {
                $this->db->rollBack();
                return false;
            }

            $challengeLock = $this->db->prepare(
                "SELECT id
                 FROM registration_email_challenges
                 WHERE id = ?
                   AND queue_id = ?
                   AND token_hash = ?
                   AND consumed_at IS NULL
                   AND revoked_at IS NULL
                   AND expires_at > NOW()
                 FOR UPDATE"
            );
            $challengeLock->execute([$challengeId, $queueId, $tokenHash]);
            if (!$challengeLock->fetchColumn()) {
                $this->db->rollBack();
                return false;
            }

            $consume = $this->db->prepare(
                "UPDATE registration_email_challenges
                 SET consumed_at = NOW()
                 WHERE id = ? AND consumed_at IS NULL AND revoked_at IS NULL"
            );
            $consume->execute([$challengeId]);
            if ($consume->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            // Only the accepted token should remain usable. This also repairs
            // any unexpected duplicate-active challenge state defensively.
            $revokeOthers = $this->db->prepare(
                "UPDATE registration_email_challenges
                 SET revoked_at = NOW()
                 WHERE queue_id = ?
                   AND id <> ?
                   AND consumed_at IS NULL
                   AND revoked_at IS NULL"
            );
            $revokeOthers->execute([$queueId, $challengeId]);

            $release = $this->db->prepare(
                "UPDATE registration_intake_queue
                 SET status = 'pending',
                     available_at = NOW(),
                     email_verified_at = NOW(),
                     email_lookup_hmac = NULL,
                     verification_expires_at = NULL,
                     started_at = NULL,
                     lease_expires_at = NULL,
                     worker_token = NULL,
                     processed_at = NULL,
                     last_error_code = NULL
                 WHERE id = ? AND status = 'awaiting_verification'"
            );
            $release->execute([$queueId]);
            if ($release->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Expire a bounded number of awaiting-verification jobs and clear all
     * encrypted profile material. These jobs are deliberately not worker backlog.
     */
    private function expireAwaitingVerificationJobs(int $limit): int
    {
        $limit = max(1, min(100, $limit));
        $this->db->beginTransaction();
        try {
            $rows = $this->db->query(
                "SELECT id
                 FROM registration_intake_queue
                 WHERE status = 'awaiting_verification'
                   AND verification_expires_at IS NOT NULL
                   AND verification_expires_at <= NOW()
                 ORDER BY id ASC
                 LIMIT " . $limit . "
                 FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($rows as $rawId) {
                $queueId = (int)$rawId;
                $revoke = $this->db->prepare(
                    "UPDATE registration_email_challenges
                     SET revoked_at = NOW()
                     WHERE queue_id = ? AND consumed_at IS NULL AND revoked_at IS NULL"
                );
                $revoke->execute([$queueId]);

                $expire = $this->db->prepare(
                    "UPDATE registration_intake_queue
                     SET status = 'expired',
                         nonce = NULL,
                         auth_tag = NULL,
                         ciphertext = NULL,
                         email_lookup_hmac = NULL,
                         verification_expires_at = NULL,
                         lease_expires_at = NULL,
                         worker_token = NULL,
                         processed_at = NOW(),
                         last_error_code = 'email_verification_expired'
                     WHERE id = ? AND status = 'awaiting_verification'"
                );
                $expire->execute([$queueId]);
                if ($expire->rowCount() !== 1) {
                    throw new RuntimeException('Expired registration queue job changed unexpectedly.');
                }
            }

            $this->db->commit();
            return count($rows);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Perform non-locking existence reads. Do not use SELECT ... FOR UPDATE
     * here: gap locks on absent identity values can deadlock concurrent inserts.
     */
    private function hasIdentityConflict(array $profile): bool
    {
        $checks = [
            ['SELECT id FROM users WHERE email = ? LIMIT 1', $profile['email']],
            ['SELECT id FROM users WHERE phone_normalized = ? LIMIT 1', normalizePhoneForComparison($profile['phone'])],
            ['SELECT id FROM users WHERE national_id = ? LIMIT 1', $profile['national_id']],
        ];

        foreach ($checks as [$sql, $value]) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$value]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create the complete patient aggregate and its audit/outbox rows in the
     * caller's transaction. Queue completion is committed in that same unit.
     *
     * @return array{0:int,1:int[]}
     */
    private function createPatientWithinTransaction(array $profile, string $emailVerifiedAt): array
    {
        if (trim($emailVerifiedAt) === '') {
            throw new RuntimeException('Patient email verification timestamp is required.');
        }

        $fullName = formatFullName($profile['first_name'], $profile['last_name']);
        $patientNumber = generatePatientNumber($this->db);

        $isV2 = $this->schema === self::SCHEMA_V2;

        if ($isV2) {
            $stmt = $this->db->prepare(
                "INSERT INTO users
                    (name, first_name, last_name, email, password_hash, phone, role, national_id,
                     patient_number, email_verification_required, email_verified_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'patient', ?, ?, 1, ?, NOW())"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO users
                    (name, first_name, last_name, email, password, phone, role, national_id, patient_number,
                     email_verification_required, email_verified_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'patient', ?, ?, 1, ?, NOW())"
            );
        }
        $stmt->execute([
            $fullName,
            $profile['first_name'],
            $profile['last_name'],
            $profile['email'],
            $profile['password_hash'],
            $profile['phone'],
            $profile['national_id'],
            $patientNumber,
            $emailVerifiedAt,
        ]);
        $userId = (int)$this->db->lastInsertId();

        $this->db->prepare('INSERT IGNORE INTO user_preferences (user_id) VALUES (?)')->execute([$userId]);

        if ($isV2) {
            $governorateText = trim((string)$profile['governorate']);
            $cityText = trim((string)$profile['city']);
            $governorateId = null;
            $cityId = null;

            if ($governorateText !== '') {
                $governorateStmt = $this->db->prepare(
                    'SELECT id FROM egypt_governorates WHERE name=? LIMIT 1'
                );
                $governorateStmt->execute([$governorateText]);
                $governorateValue = $governorateStmt->fetchColumn();
                $governorateId = $governorateValue !== false ? (int)$governorateValue : null;
            }
            if ($cityText !== '' && $governorateId !== null) {
                $cityStmt = $this->db->prepare(
                    'SELECT id FROM egypt_cities WHERE governorate_id=? AND name=? LIMIT 1'
                );
                $cityStmt->execute([$governorateId, $cityText]);
                $cityValue = $cityStmt->fetchColumn();
                $cityId = $cityValue !== false ? (int)$cityValue : null;
            }

            $patientProfile = $this->db->prepare(
                "INSERT INTO patient_profiles
                    (patient_user_id,date_of_birth,gender,governorate_id,city_id,
                     governorate_text,city_text,address,insurance_provider,insurance_number,
                     emergency_name,emergency_relation,emergency_phone,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"
            );
            $patientProfile->execute([
                $userId,
                $profile['date_of_birth'] !== '' ? $profile['date_of_birth'] : null,
                $profile['gender'] !== '' ? $profile['gender'] : null,
                $governorateId,
                $cityId,
                $governorateText !== '' ? $governorateText : null,
                $cityText !== '' ? $cityText : null,
                $profile['address'] !== '' ? $profile['address'] : null,
                $profile['insurance_provider'] !== '' ? $profile['insurance_provider'] : null,
                $profile['insurance_number'] !== '' ? $profile['insurance_number'] : null,
                $profile['emergency_contact_name'] !== '' ? $profile['emergency_contact_name'] : null,
                $profile['emergency_contact_rel'] !== '' ? $profile['emergency_contact_rel'] : null,
                $profile['emergency_contact_phone'] !== '' ? $profile['emergency_contact_phone'] : null,
            ]);

            $medicalProfile = $this->db->prepare(
                "INSERT INTO patient_medical_profiles
                    (patient_user_id,blood_type,allergies,chronic_diseases,current_medications,created_at,updated_at)
                 VALUES (?,?,?,?,?,NOW(),NOW())"
            );
            $medicalProfile->execute([
                $userId,
                $profile['blood_type'] !== '' ? $profile['blood_type'] : null,
                $profile['allergies'] !== '' ? $profile['allergies'] : null,
                $profile['chronic_diseases'] !== '' ? $profile['chronic_diseases'] : null,
                $profile['current_medications'] !== '' ? $profile['current_medications'] : null,
            ]);

            $auditStmt = $this->db->prepare(
                "INSERT INTO audit_events
                    (actor_user_id,actor_role_snapshot,action_code,entity_type,entity_id,
                     patient_user_id,old_value,new_value,description,ip_address,user_agent)
                 VALUES (?,'patient','create','patient',?,?,NULL,?,?,?,?)"
            );
            $auditStmt->execute([
                $userId,
                (string)$userId,
                $userId,
                json_encode([
                    'first_name' => $profile['first_name'],
                    'patient_number' => $patientNumber,
                    'national_id' => '[REDACTED]',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'New patient account fully registered with patient number: ' . $patientNumber,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO medical_records (
                    patient_id, date_of_birth, gender, blood_type, governorate, city, address,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_rel,
                    allergies, chronic_diseases, current_medications,
                    insurance_provider, insurance_number, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([
                $userId,
                $profile['date_of_birth'] !== '' ? $profile['date_of_birth'] : null,
                $profile['gender'],
                $profile['blood_type'] !== '' ? $profile['blood_type'] : null,
                $profile['governorate'],
                $profile['city'],
                $profile['address'],
                $profile['emergency_contact_name'],
                $profile['emergency_contact_phone'],
                $profile['emergency_contact_rel'],
                $profile['allergies'] !== '' ? $profile['allergies'] : null,
                $profile['chronic_diseases'] !== '' ? $profile['chronic_diseases'] : null,
                $profile['current_medications'] !== '' ? $profile['current_medications'] : null,
                $profile['insurance_provider'] !== '' ? $profile['insurance_provider'] : null,
                $profile['insurance_number'] !== '' ? $profile['insurance_number'] : null,
            ]);

            $audit = new AuditService($this->db, $userId, 'patient', $this->schema);
            $auditLogged = $audit->log(
                'create',
                'patient',
                $userId,
                null,
                [
                    'first_name' => $profile['first_name'],
                    'patient_number' => $patientNumber,
                    'national_id' => '[REDACTED]',
                ],
                'New patient account fully registered with patient number: ' . $patientNumber,
                $userId,
                null
            );
            if (!$auditLogged) {
                throw new RuntimeException('Registration audit insert failed.');
            }
        }

        $adminStmt = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        $notifications = new NotificationService($this->db);
        foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $notifications->create(
                (int)$adminId,
                NotificationService::TYPE_NEW_PATIENT,
                'New Patient Registered',
                "{$fullName} ({$patientNumber}) has completed registration.",
                'user',
                $userId
            );
        }

        $outboxStmt = $this->db->prepare(
            "SELECT id FROM notification_outbox
             WHERE ref_type = 'user' AND ref_id = ? AND type = ? AND status = 'pending'
             ORDER BY id ASC"
        );
        $outboxStmt->execute([$userId, NotificationService::TYPE_NEW_PATIENT]);
        $outboxIds = array_map('intval', $outboxStmt->fetchAll(PDO::FETCH_COLUMN));

        return [$userId, $outboxIds];
    }

    private function completeQueueJobWithinTransaction(int $id, string $token, string $status): void
    {
        $stmt = $this->db->prepare(
            "UPDATE registration_intake_queue
             SET status = ?,
                 nonce = NULL,
                 auth_tag = NULL,
                 ciphertext = NULL,
                 email_lookup_hmac = NULL,
                 verification_expires_at = NULL,
                 lease_expires_at = NULL,
                 worker_token = NULL,
                 processed_at = NOW(),
                 last_error_code = NULL
             WHERE id = ? AND status = 'processing' AND worker_token = ?"
        );
        $stmt->execute([$status, $id, $token]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Registration queue completion state could not be committed.');
        }
    }

    private function markTerminal(int $id, string $token, string $status, string $errorCode): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE registration_intake_queue
                 SET status = ?,
                     nonce = NULL,
                     auth_tag = NULL,
                     ciphertext = NULL,
                     email_lookup_hmac = NULL,
                     verification_expires_at = NULL,
                     lease_expires_at = NULL,
                     worker_token = NULL,
                     processed_at = NOW(),
                     last_error_code = ?
                 WHERE id = ? AND status = 'processing' AND worker_token = ?"
            );
            $stmt->execute([$status, $errorCode !== '' ? $errorCode : null, $id, $token]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Registration queue terminal state was not owned by this worker.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function retryOrFail(int $id, string $token, int $attempt, string $errorCode): void
    {
        $terminal = $attempt >= self::MAX_ATTEMPTS;
        $delay = min(30 * (2 ** max(0, $attempt - 1)), 900);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE registration_intake_queue
                 SET status = ?,
                     available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     nonce = CASE WHEN ? = 1 THEN NULL ELSE nonce END,
                     auth_tag = CASE WHEN ? = 1 THEN NULL ELSE auth_tag END,
                     ciphertext = CASE WHEN ? = 1 THEN NULL ELSE ciphertext END,
                     email_lookup_hmac = CASE WHEN ? = 1 THEN NULL ELSE email_lookup_hmac END,
                     verification_expires_at = CASE WHEN ? = 1 THEN NULL ELSE verification_expires_at END,
                     lease_expires_at = NULL,
                     worker_token = NULL,
                     processed_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                     last_error_code = ?
                 WHERE id = ? AND status = 'processing' AND worker_token = ?"
            );
            $done = $terminal ? 1 : 0;
            $stmt->execute([
                $terminal ? 'failed' : 'pending',
                $delay,
                $done,
                $done,
                $done,
                $done,
                $done,
                $done,
                $errorCode,
                $id,
                $token,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Registration queue retry state was not owned by this worker.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Dispatch only this registration's outbox events after the account/job
     * transaction has committed. A crash here leaves durable outbox work for
     * the normal notification worker to pick up.
     *
     * @param int[] $outboxIds
     * @return array<string,int>
     */
    private function dispatchRegistrationNotifications(array $outboxIds): array
    {
        $stats = ['delivered' => 0, 'skipped' => 0];

        if ($outboxIds === []) {
            return $stats;
        }

        $service = new NotificationService($this->db);
        foreach ($outboxIds as $eventId) {
            try {
                $result = $service->processOutbox(1, $eventId);
                $stats['delivered'] += (int)($result['delivered'] ?? 0);
                $stats['skipped'] += (int)($result['skipped'] ?? 0);
            } catch (Throwable $error) {
                error_log('Registration intake notification dispatch failed: ' . get_class($error));
            }
        }

        return $stats;
    }

    private static function isIdentityUniqueViolation(PDOException $error): bool
    {
        if ((string)$error->getCode() !== '23000' || (int)($error->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }

        $message = strtolower((string)($error->errorInfo[2] ?? ''));
        foreach (['email', 'national_id', 'uq_users_phone_normalized'] as $key) {
            if (str_contains($message, "for key '{$key}'") || str_contains($message, "for key `{$key}`")) {
                return true;
            }
        }

        return false;
    }

    private static function safeErrorCode(PDOException $error): string
    {
        $driverCode = $error->errorInfo[1] ?? null;
        if (is_numeric($driverCode)) {
            return 'db_driver_' . (int)$driverCode;
        }

        $sqlState = preg_replace('/[^A-Za-z0-9]/', '', (string)$error->getCode()) ?: 'unknown';
        return 'db_state_' . substr($sqlState, 0, 16);
    }

    private static function workerLockPath(): string
    {
        $applicationRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        $fingerprint = substr(hash('sha256', $applicationRoot), 0, 16);
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'healthbridge-registration-intake-' . $fingerprint . '.lock';
    }

    /**
     * @return array{0:array<string,string>,1:string}
     */
    private static function loadKeyRing(): array
    {
        $raw = getenv('HB_REGISTRATION_QUEUE_KEYS_JSON');
        if (!is_string($raw) || trim($raw) === '') {
            $raw = isset($_ENV['HB_REGISTRATION_QUEUE_KEYS_JSON'])
                ? (string)$_ENV['HB_REGISTRATION_QUEUE_KEYS_JSON']
                : '';
        }
        if ($raw === '') {
            throw new RuntimeException('Registration encryption keyring is not configured.');
        }

        try {
            $encodedKeys = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('Registration encryption keyring configuration is invalid.');
        }

        if (!is_array($encodedKeys) || $encodedKeys === []) {
            throw new RuntimeException('Registration encryption keyring configuration is invalid.');
        }

        $keys = [];
        foreach ($encodedKeys as $version => $encodedKey) {
            $version = (string)$version;
            if (!preg_match('/^[A-Za-z0-9._-]{1,24}$/', $version) || !is_string($encodedKey)) {
                throw new RuntimeException('Registration encryption keyring configuration is invalid.');
            }
            $key = base64_decode($encodedKey, true);
            if (!is_string($key) || strlen($key) !== 32) {
                throw new RuntimeException('Registration encryption keyring contains an invalid key.');
            }
            $keys[$version] = $key;
        }

        $activeVersion = getenv('HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION');
        if (!is_string($activeVersion) || $activeVersion === '') {
            $activeVersion = (string)($_ENV['HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION'] ?? '');
        }
        if ($activeVersion === '' || !isset($keys[$activeVersion])) {
            throw new RuntimeException('Registration active encryption key version is unavailable.');
        }

        return [$keys, $activeVersion];
    }
}
