<?php
/**
 * HealthBridge — Update Hospital Settings
 * Updates the global hospital appointment configuration and hospital information.
 * Includes conflict detection for schedule changes and audit logging.
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/HospitalSettingsService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$input = getJsonInput();

$textFields = [
    'appointment_open_time',
    'appointment_close_time',
    'hospital_name',
    'hospital_phone',
    'hospital_email',
    'hospital_address',
    'hospital_description',
];

foreach ($textFields as $field) {
    if (array_key_exists($field, $input) && $input[$field] !== null && !is_string($input[$field])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Hospital setting fields must be text values.']);
    }
}

$openTime = $input['appointment_open_time'] ?? null;
$closeTime = $input['appointment_close_time'] ?? null;
$durationValue = $input['default_appointment_duration'] ?? null;
$duration = null;

if ($durationValue !== null) {
    if ((!is_int($durationValue) && !is_string($durationValue)) || filter_var($durationValue, FILTER_VALIDATE_INT) === false) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Default appointment duration must be an integer.']);
    }
    $duration = (int)$durationValue;
    if (!in_array($duration, HospitalSettingsService::VALID_DURATIONS, true)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Invalid default appointment duration.']);
    }
}

// Hospital info fields (optional)
$hospitalName = $input['hospital_name'] ?? null;
$hospitalPhone = $input['hospital_phone'] ?? null;
$hospitalEmail = $input['hospital_email'] ?? null;
$hospitalAddress = $input['hospital_address'] ?? null;
$hospitalDescription = $input['hospital_description'] ?? null;

foreach (['appointment_open_time' => $openTime, 'appointment_close_time' => $closeTime] as $field => $time) {
    if ($time !== null && ($time === '' || !preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', $time))) {
        http_response_code(400);
        jsonResponse(false, ['message' => ucfirst(str_replace('_', ' ', $field)) . ' must be in HH:MM format.']);
    }
}

if ($hospitalName !== null && strlen($hospitalName) > 200) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Hospital name must not exceed 200 characters.']);
}
if ($hospitalPhone !== null && $hospitalPhone !== '' && !preg_match('/^[\\d\\s\\+\\-\\(\\)]+$/', $hospitalPhone)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Hospital phone must contain only valid phone number characters.']);
}
if ($hospitalEmail !== null && $hospitalEmail !== '' && !filter_var($hospitalEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Hospital email must be a valid email address.']);
}

if (array_key_exists('confirmed', $input) && !is_bool($input['confirmed'])) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Confirmation flag must be a boolean.']);
}
$confirmed = $input['confirmed'] ?? false;

$hasUpdate = $duration !== null;
foreach ($textFields as $field) {
    if (array_key_exists($field, $input) && $input[$field] !== null) {
        $hasUpdate = true;
        break;
    }
}
if (!$hasUpdate) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'At least one field must be provided.']);
}

try {
    $db = getDB();
    $hs = new HospitalSettingsService($db);
    $audit = new AdminAuditService($db, (int)$user['id']);

    // Get current settings for audit and calculate the complete proposed hours,
    // including the unchanged counterpart when only one time is being edited.
    $oldSettings = $hs->getSettings();
    $effectiveOpenTime = $openTime ?? ($oldSettings['appointment_open_time'] ?? '08:00');
    $effectiveCloseTime = $closeTime ?? ($oldSettings['appointment_close_time'] ?? '22:00');

    if ($effectiveOpenTime >= $effectiveCloseTime) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Appointment opening time must be before closing time.']);
    }

    // Any change to either side of the hospital hours must check existing schedules.
    if (($openTime !== null || $closeTime !== null) && !$confirmed) {
        $conflicts = $hs->checkScheduleConflicts($effectiveOpenTime, $effectiveCloseTime);
        if (!empty($conflicts)) {
            $conflictCount = count($conflicts);
            $conflictDetails = array_slice($conflicts, 0, 5);

            jsonResponse(false, [
                'message' => "Changing appointment hours would affect {$conflictCount} doctor schedule(s). Please review and confirm.",
                'conflicts' => $conflictDetails,
                'conflict_count' => $conflictCount,
                'requires_confirmation' => true,
            ]);
        }
    }

    $db->beginTransaction();

    try {
        $updateData = [];
        if ($openTime !== null) $updateData['appointment_open_time'] = $openTime;
        if ($closeTime !== null) $updateData['appointment_close_time'] = $closeTime;
        if ($duration !== null) $updateData['default_appointment_duration'] = $duration;
        if ($hospitalName !== null) $updateData['hospital_name'] = $hospitalName;
        if ($hospitalPhone !== null) $updateData['hospital_phone'] = $hospitalPhone;
        if ($hospitalEmail !== null) $updateData['hospital_email'] = $hospitalEmail;
        if ($hospitalAddress !== null) $updateData['hospital_address'] = $hospitalAddress;
        if ($hospitalDescription !== null) $updateData['hospital_description'] = $hospitalDescription;

        $hs->updateSettings($updateData);
        $newSettings = $hs->getSettings();
        $audit->logHospitalSettingsChange($oldSettings, $newSettings);

        $db->commit();

        jsonResponse(true, ['message' => 'Hospital settings updated successfully.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Update Hospital Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update hospital settings.']);
}
