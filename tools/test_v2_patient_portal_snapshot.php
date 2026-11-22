<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const PORTAL_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const PORTAL_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n");
    exit(11);
}

require_once __DIR__ . '/../services/PatientPortalService.php';

function portalConnect(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [PORTAL_V1_FIXTURE, PORTAL_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on the exact patient portal fixture allowlist.');
    }
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema did not match the fixture allowlist.');
    }
    return $db;
}

function portalAssertSame(mixed $expected, mixed $actual, string $context): void
{
    if (is_array($expected) || is_array($actual)) {
        $matches = $expected == $actual;
    } else {
        $matches = (string)($expected ?? '') === (string)($actual ?? '');
    }
    if (!$matches) {
        throw new RuntimeException($context . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

try {
    $v1 = portalConnect(PORTAL_V1_FIXTURE, $host, $port, $user, $password);
    $v2 = portalConnect(PORTAL_V2_FIXTURE, $host, $port, $user, $password);
    $v1Portal = new PatientPortalService($v1, 'v1');
    $v2Portal = new PatientPortalService($v2, 'v2');
    $patientIds = array_map('intval', $v1->query("SELECT id FROM users WHERE role = 'patient' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    if (count($patientIds) !== 133) {
        throw new RuntimeException('Expected exactly 133 synthetic patients.');
    }

    $snapshotKeys = [
        'total_appointments', 'upcoming_appointments', 'completed_appointments',
        'active_prescriptions', 'total_prescriptions', 'has_medical_record',
        'total_notifications', 'unread_notifications', 'doctors_seen', 'last_visit',
        'cancelled_appointments', 'missed_appointments', 'total_prescriptions_issued',
        'profile_completion',
    ];
    $profileKeys = ['percentage', 'total_checks', 'completed'];
    $doctorAvailability = [];
    foreach ($v2->query('SELECT doctor_user_id, is_available FROM doctor_schedule_settings')->fetchAll() as $availabilityRow) {
        $doctorAvailability[(int)$availabilityRow['doctor_user_id']] = (int)$availabilityRow['is_available'] === 1;
    }
    foreach ($patientIds as $patientId) {
        $v1Profile = $v1Portal->getProfileCompletion($patientId);
        $v2Profile = $v2Portal->getProfileCompletion($patientId);
        foreach ($profileKeys as $key) {
            portalAssertSame($v1Profile[$key] ?? null, $v2Profile[$key] ?? null, "patient {$patientId} profile {$key}");
        }
        portalAssertSame(
            array_column($v1Profile['missing_fields'], 'field'),
            array_column($v2Profile['missing_fields'], 'field'),
            "patient {$patientId} missing profile fields"
        );

        portalAssertSame(
            $v1Portal->getMedicalProfile($patientId),
            $v2Portal->getMedicalProfile($patientId),
            "patient {$patientId} medical profile"
        );

        $v1Favorites = $v1Portal->getFavorites($patientId);
        $v2Favorites = $v2Portal->getFavorites($patientId);
        $favoriteMetrics = [
            'most_visited' => 'visit_count',
            'top_rated' => 'patient_rating',
            'recently_visited' => 'last_visit',
        ];
        foreach ($favoriteMetrics as $favoriteGroup => $metric) {
            $expectedRows = $v1Favorites[$favoriteGroup];
            $actualRows = $v2Favorites[$favoriteGroup];
            if (count($expectedRows) !== count($actualRows)) {
                throw new RuntimeException("patient {$patientId} favorites {$favoriteGroup} row count differs.");
            }

            $keyRows = static function (array $rows): array {
                $indexed = [];
                foreach ($rows as $row) {
                    unset($row['rating'], $row['available']); // v1 caches rating/availability in a legacy doctor row.
                    $key = (int)$row['doctor_id'] . '|' . (string)($row['department'] ?? '');
                    $indexed[$key] = $row;
                }
                return $indexed;
            };
            $expectedByKey = $keyRows($expectedRows);
            $actualByKey = $keyRows($actualRows);
            foreach (array_intersect_key($expectedByKey, $actualByKey) as $key => $row) {
                portalAssertSame($row, $actualByKey[$key], "patient {$patientId} favorites {$favoriteGroup} {$key}");
            }

            $missingKeys = array_diff(array_keys($expectedByKey), array_keys($actualByKey));
            $extraKeys = array_diff(array_keys($actualByKey), array_keys($expectedByKey));
            if ($missingKeys !== [] || $extraKeys !== []) {
                $boundaryValues = array_merge(
                    array_column($expectedRows, $metric),
                    array_column($actualRows, $metric)
                );
                $boundary = $boundaryValues === [] ? null : min($boundaryValues);
                foreach ([$missingKeys, $extraKeys] as $differentKeys) {
                    $sourceRows = array_merge($expectedByKey, $actualByKey);
                    foreach ($differentKeys as $key) {
                        if ($boundary === null || ($sourceRows[$key][$metric] ?? null) != $boundary) {
                            throw new RuntimeException(
                                "patient {$patientId} favorites {$favoriteGroup} differs beyond an undefined cutoff tie; boundary="
                                . var_export($boundary, true)
                                . '; expected=' . var_export($expectedRows, true)
                                . '; actual=' . var_export($actualRows, true)
                            );
                        }
                    }
                }
            }
        }
        foreach ($v2Favorites as $favoriteGroup => $favoriteRows) {
            foreach ($favoriteRows as $favoriteDoctor) {
                $favoriteDoctorId = (int)$favoriteDoctor['doctor_id'];
                if (!array_key_exists($favoriteDoctorId, $doctorAvailability)
                    || $favoriteDoctor['available'] !== $doctorAvailability[$favoriteDoctorId]) {
                    throw new RuntimeException("patient {$patientId} favorites {$favoriteGroup} availability does not match doctor schedule settings.");
                }
            }
        }
        foreach ($v2Favorites['recommended'] as $recommendedDoctor) {
            $rating = $recommendedDoctor['rating'] ?? null;
            if ((int)$recommendedDoctor['doctor_id'] <= 0
                || !is_numeric($rating)
                || (float)$rating < 0
                || (float)$rating > 5
                || $recommendedDoctor['available'] !== true) {
                throw new RuntimeException("patient {$patientId} v2 recommended doctor DTO is invalid.");
            }
        }

        $v1Notifications = $v1Portal->getNotifications($patientId);
        $v2Notifications = $v2Portal->getNotifications($patientId);
        $normalizeNotifications = static function (array $payload): array {
            foreach (['grouped', 'by_type'] as $section) {
                foreach ($payload[$section] as &$items) {
                    foreach ($items as &$item) {
                        unset($item['time_ago']);
                    }
                    unset($item);
                    usort($items, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
                }
                unset($items);
            }
            return $payload;
        };
        portalAssertSame(
            $normalizeNotifications($v1Notifications),
            $normalizeNotifications($v2Notifications),
            "patient {$patientId} notifications"
        );

        $v1Overview = $v1Portal->getOverview($patientId);
        $v2Overview = $v2Portal->getOverview($patientId);
        foreach (['greeting', 'current_time', 'current_date'] as $dynamicKey) {
            unset($v1Overview[$dynamicKey], $v2Overview[$dynamicKey]);
        }
        if (is_array($v1Overview['next_appointment'])) {
            unset($v1Overview['next_appointment']['countdown'], $v2Overview['next_appointment']['countdown']);
            unset($v1Overview['next_appointment']['doctor_rating'], $v2Overview['next_appointment']['doctor_rating']);
        }
        if (is_array($v1Overview['primary_doctor']) && is_array($v2Overview['primary_doctor'])) {
            unset($v1Overview['primary_doctor']['rating'], $v2Overview['primary_doctor']['rating']);
            unset($v1Overview['primary_doctor']['available'], $v2Overview['primary_doctor']['available']);

            $v1PrimaryId = (int)$v1Overview['primary_doctor']['user_id'];
            $v2PrimaryId = (int)$v2Overview['primary_doctor']['user_id'];
            if ($v1PrimaryId !== $v2PrimaryId) {
                $v1TopStmt = $v1->prepare(
                    "SELECT MAX(doctor_count) FROM (
                        SELECT COUNT(*) AS doctor_count
                        FROM appointments WHERE user_id = ?
                        GROUP BY doctor_id
                    ) grouped_counts"
                );
                $v1TopStmt->execute([$patientId]);
                $v1TopCount = (int)$v1TopStmt->fetchColumn();
                $v2TopStmt = $v2->prepare(
                    "SELECT MAX(doctor_count) FROM (
                        SELECT COUNT(*) AS doctor_count
                        FROM appointments WHERE patient_user_id = ?
                        GROUP BY doctor_user_id
                    ) grouped_counts"
                );
                $v2TopStmt->execute([$patientId]);
                $v2TopCount = (int)$v2TopStmt->fetchColumn();

                $v1DoctorCount = $v1->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND doctor_id = ?');
                $v1DoctorCount->execute([$patientId, $v1PrimaryId]);
                $v2DoctorCount = $v2->prepare('SELECT COUNT(*) FROM appointments WHERE patient_user_id = ? AND doctor_user_id = ?');
                $v2DoctorCount->execute([$patientId, $v2PrimaryId]);

                if ($v1TopCount <= 0
                    || (int)$v1DoctorCount->fetchColumn() !== $v1TopCount
                    || (int)$v2DoctorCount->fetchColumn() !== $v2TopCount
                    || $v1TopCount !== $v2TopCount) {
                    throw new RuntimeException("patient {$patientId} primary doctor differs without a verified top-count tie.");
                }
                $v1Overview['primary_doctor'] = ['tie' => 'top appointment count'];
                $v2Overview['primary_doctor'] = ['tie' => 'top appointment count'];
            }
        }
        portalAssertSame($v1Overview, $v2Overview, "patient {$patientId} dashboard overview");

        $v1Snapshot = $v1Portal->getHealthSnapshot($patientId);
        $v2Snapshot = $v2Portal->getHealthSnapshot($patientId);
        foreach ($snapshotKeys as $key) {
            portalAssertSame($v1Snapshot[$key] ?? null, $v2Snapshot[$key] ?? null, "patient {$patientId} snapshot {$key}");
        }

        portalAssertSame(
            $v1Portal->getHealthAlerts($patientId),
            $v2Portal->getHealthAlerts($patientId),
            "patient {$patientId} health alerts"
        );
        $v1Insights = $v1Portal->getHealthInsights($patientId);
        $v2Insights = $v2Portal->getHealthInsights($patientId);
        $normalizeInsightOrdering = static function (array $insights): array {
            $distribution = $insights['department_distribution'];
            usort(
                $distribution,
                static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name'])
            );
            $insights['department_distribution'] = $distribution;

            // The legacy top-department query does not define a tie-breaker.
            // Any department sharing the maximum count is an equivalent winner.
            if ($distribution !== []) {
                $maxCount = max(array_column($distribution, 'count'));
                $tiedWinners = array_values(array_filter(
                    $distribution,
                    static fn(array $row): bool => (int)$row['count'] === (int)$maxCount
                ));
                if (count($tiedWinners) > 1) {
                    portalAssertSame($maxCount, $insights['most_visited_department']['count'] ?? null, 'top department maximum count');
                    $insights['most_visited_department'] = ['name' => '[tied maximum]', 'count' => (int)$maxCount];
                }
            }
            return $insights;
        };
        portalAssertSame(
            $normalizeInsightOrdering($v1Insights),
            $normalizeInsightOrdering($v2Insights),
            "patient {$patientId} health insights"
        );

        $v1Downloads = $v1Portal->getDownloads($patientId);
        $v2Downloads = $v2Portal->getDownloads($patientId);
        foreach (['prescriptions', 'confirmations', 'visit_summaries'] as $collection) {
            usort(
                $v1Downloads[$collection],
                static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']
            );
            usort(
                $v2Downloads[$collection],
                static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']
            );
        }
        portalAssertSame($v1Downloads, $v2Downloads, "patient {$patientId} downloads");

        $doctorStmt = $v1->prepare(
            "SELECT u.name FROM appointments a JOIN users u ON u.id = a.doctor_id
             WHERE a.user_id = ? ORDER BY a.id LIMIT 1"
        );
        $doctorStmt->execute([$patientId]);
        $doctorTerm = (string)($doctorStmt->fetchColumn() ?: '');
        if (strlen($doctorTerm) >= 2) {
            $v1Search = $v1Portal->searchAll($patientId, $doctorTerm, 10);
            $v2Search = $v2Portal->searchAll($patientId, $doctorTerm, 10);
            foreach (['appointments', 'doctors', 'prescriptions', 'history'] as $collection) {
                usort(
                    $v1Search[$collection],
                    static fn(array $a, array $b): int => (int)($a['id'] ?? $a['doctor_id'] ?? 0) <=> (int)($b['id'] ?? $b['doctor_id'] ?? 0)
                );
                usort(
                    $v2Search[$collection],
                    static fn(array $a, array $b): int => (int)($a['id'] ?? $a['doctor_id'] ?? 0) <=> (int)($b['id'] ?? $b['doctor_id'] ?? 0)
                );
            }
            foreach ($v2Search['doctors'] as $doctor) {
                $rating = $doctor['rating'] ?? null;
                if ($rating !== null && (!is_numeric($rating) || (float)$rating < 0 || (float)$rating > 5)) {
                    throw new RuntimeException("patient {$patientId} v2 doctor search returned an invalid rating.");
                }
            }
            // v1 doctors.rating is a stale cached field for 30/32 doctors in
            // this fixture; v2 intentionally uses the live aggregate rating view.
            foreach ($v1Search['doctors'] as &$doctor) {
                unset($doctor['rating']);
            }
            unset($doctor);
            foreach ($v2Search['doctors'] as &$doctor) {
                unset($doctor['rating']);
            }
            unset($doctor);
            portalAssertSame($v1Search, $v2Search, "patient {$patientId} doctor search");
        }

        $diagnosisStmt = $v1->prepare(
            "SELECT diagnosis FROM visit_notes
             WHERE patient_id = ? AND diagnosis IS NOT NULL AND diagnosis <> ''
             ORDER BY id LIMIT 1"
        );
        $diagnosisStmt->execute([$patientId]);
        $diagnosisTerm = (string)($diagnosisStmt->fetchColumn() ?: '');
        if (strlen($diagnosisTerm) >= 2) {
            $v1Search = $v1Portal->searchAll($patientId, $diagnosisTerm, 10);
            $v2Search = $v2Portal->searchAll($patientId, $diagnosisTerm, 10);
            foreach (['appointments', 'doctors', 'prescriptions', 'history'] as $collection) {
                usort(
                    $v1Search[$collection],
                    static fn(array $a, array $b): int => (int)($a['id'] ?? $a['doctor_id'] ?? 0) <=> (int)($b['id'] ?? $b['doctor_id'] ?? 0)
                );
                usort(
                    $v2Search[$collection],
                    static fn(array $a, array $b): int => (int)($a['id'] ?? $a['doctor_id'] ?? 0) <=> (int)($b['id'] ?? $b['doctor_id'] ?? 0)
                );
            }
            foreach ($v2Search['doctors'] as $doctor) {
                $rating = $doctor['rating'] ?? null;
                if ($rating !== null && (!is_numeric($rating) || (float)$rating < 0 || (float)$rating > 5)) {
                    throw new RuntimeException("patient {$patientId} v2 doctor search returned an invalid rating.");
                }
            }
            foreach ($v1Search['doctors'] as &$doctor) {
                unset($doctor['rating']);
            }
            unset($doctor);
            foreach ($v2Search['doctors'] as &$doctor) {
                unset($doctor['rating']);
            }
            unset($doctor);
            portalAssertSame($v1Search, $v2Search, "patient {$patientId} diagnosis search");
        }
    }

    echo "PATIENT_PROFILE_PARITY|133|PASS\n";
    echo "PATIENT_MEDICAL_PROFILE_PARITY|133|PASS\n";
    echo "PATIENT_FAVORITES_COMPATIBILITY|133|PASS\n";
    echo "PATIENT_OVERVIEW_PARITY|133|PASS\n";
    echo "PATIENT_NOTIFICATIONS_PARITY|133|PASS\n";
    echo "PATIENT_HEALTH_SNAPSHOT_PARITY|133|14_fields_each|PASS\n";
    echo "PATIENT_HEALTH_ALERTS_PARITY|133|PASS\n";
    echo "PATIENT_HEALTH_INSIGHTS_PARITY|133|PASS\n";
    echo "PATIENT_PORTAL_SNAPSHOT_SUITE=PASS\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "PATIENT_PORTAL_SNAPSHOT_SUITE=FAIL\n" . $error->getMessage() . "\n");
    exit(1);
}
