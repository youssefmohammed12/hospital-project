<?php
/**
 * HealthBridge — Patient medical profile update (Phase 6).
 * Lets a signed-in patient update their OWN medical/personal record:
 * the patient_profiles and patient_medical_profiles rows keyed by their
 * own user id. POST JSON; every field is optional and validated.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';

$user = requireAuth();
if ((string)$user['role'] !== 'patient') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Medical profile editing is available to patient accounts only.']);
}
$userId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

// The editable tables exist only in the V2 schema.
$portalSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PORTAL_SCHEMA');
if ($portalSchema !== 'v2') {
    http_response_code(409);
    jsonResponse(false, ['message' => 'Medical profile editing is not available on this records system.']);
}

$data = getJsonInput();
if (!is_array($data)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid request body.']);
}

$fail = static function (string $message): void {
    http_response_code(400);
    jsonResponse(false, ['message' => $message]);
};

$text = static function (array $data, string $key, int $max) use ($fail): ?string {
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    if (!is_string($data[$key])) {
        $fail('Field ' . $key . ' must be text.');
    }
    return sanitizeString($data[$key], $max);
};

// ── patient_profiles fields ──────────────────────────────────────────
$profile = [];

$dob = $text($data, 'date_of_birth', 10);
if ($dob !== null) {
    if ($dob === '') {
        $profile['date_of_birth'] = null;
    } else {
        $parsed = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$parsed || $parsed->format('Y-m-d') !== $dob
            || $dob > date('Y-m-d') || $dob < '1900-01-01') {
            $fail('Date of birth must be a valid past date.');
        }
        $profile['date_of_birth'] = $dob;
    }
}

$gender = $text($data, 'gender', 20);
if ($gender !== null) {
    $canonical = ['male' => 'Male', 'female' => 'Female', '' => null];
    $key = strtolower($gender);
    if (!array_key_exists($key, $canonical)) {
        $fail('Gender must be Male or Female.');
    }
    $profile['gender'] = $canonical[$key];
}

foreach ([
    'emergency_name' => 100,
    'emergency_relation' => 50,
    'emergency_phone' => 20,
    'governorate_text' => 100,
    'city_text' => 100,
    'address' => 255,
    'insurance_provider' => 100,
    'insurance_number' => 100,
] as $key => $max) {
    $value = $text($data, $key, $max);
    if ($value !== null) {
        $profile[$key] = $value === '' ? null : $value;
    }
}

if (isset($profile['emergency_phone']) && $profile['emergency_phone'] !== null
    && !preg_match('/^[0-9+\s()\-]{7,20}$/', $profile['emergency_phone'])) {
    $fail('Emergency contact phone looks invalid.');
}

// ── patient_medical_profiles fields ─────────────────────────────────
$medical = [];

$blood = $text($data, 'blood_type', 5);
if ($blood !== null) {
    if ($blood === '') {
        $medical['blood_type'] = null;
    } elseif (!in_array($blood, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) {
        $fail('Blood type must be one of A+, A-, B+, B-, AB+, AB-, O+, O-.');
    } else {
        $medical['blood_type'] = $blood;
    }
}

foreach (['height_cm' => [50, 260], 'weight_kg' => [20, 500]] as $key => [$min, $max]) {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        if (array_key_exists($key, $data)) {
            $medical[$key] = null;
        }
        continue;
    }
    if (!is_numeric($data[$key])) {
        $fail('Field ' . $key . ' must be a number.');
    }
    $number = round((float)$data[$key], 1);
    if ($number < $min || $number > $max) {
        $fail('Field ' . $key . ' is outside the accepted range.');
    }
    $medical[$key] = $number;
}

foreach ([
    'allergies' => 1000,
    'chronic_diseases' => 1000,
    'current_medications' => 1000,
    'previous_surgeries' => 1000,
    'family_history' => 1000,
    'medical_notes' => 1000,
] as $key => $max) {
    $value = $text($data, $key, $max);
    if ($value !== null) {
        $medical[$key] = $value === '' ? null : $value;
    }
}

if (empty($profile) && empty($medical)) {
    $fail('Nothing to update.');
}

try {
    $db = getDB();
    $db->beginTransaction();

    $upsert = static function (PDO $db, string $table, int $userId, array $fields): void {
        $columns = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(
            static fn(string $column): string => $column . ' = VALUES(' . $column . ')',
            $columns
        ));
        $sql = 'INSERT INTO ' . $table . ' (patient_user_id, ' . implode(', ', $columns) . ')'
             . ' VALUES (?, ' . $placeholders . ')'
             . ' ON DUPLICATE KEY UPDATE ' . $updates;
        $db->prepare($sql)->execute(array_merge([$userId], array_values($fields)));
    };

    if (!empty($profile)) {
        $upsert($db, 'patient_profiles', $userId, $profile);
    }
    if (!empty($medical)) {
        $upsert($db, 'patient_medical_profiles', $userId, $medical);
    }

    $changed = array_merge(array_keys($profile), array_keys($medical));
    $audit = new AuditService($db, $userId, 'patient', AuditService::schemaFromEnvironment());
    $logged = $audit->log(
        'update',
        'patient',
        $userId,
        '[REDACTED]',
        '[REDACTED]',
        'Medical profile updated by patient: ' . implode(', ', $changed),
        $userId,
        null
    );
    if (!$logged) {
        throw new RuntimeException('Could not record the medical profile audit event.');
    }

    $db->commit();
    jsonResponse(true, ['message' => 'Medical information saved.']);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Medical Profile Update Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to save medical information.']);
}
