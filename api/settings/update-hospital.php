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
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$input = getJsonInput();

$openTime = $input['appointment_open_time'] ?? null;
$closeTime = $input['appointment_close_time'] ?? null;
$duration = isset($input['default_appointment_duration']) ? (int)$input['default_appointment_duration'] : null;

// Hospital info fields (optional)
$hospitalName = $input['hospital_name'] ?? null;
$hospitalPhone = $input['hospital_phone'] ?? null;
$hospitalEmail = $input['hospital_email'] ?? null;
$hospitalAddress = $input['hospital_address'] ?? null;
$hospitalDescription = $input['hospital_description'] ?? null;

// At least one field must be provided
if (!$openTime && !$closeTime && !$duration && !$hospitalName && !$hospitalPhone && !$hospitalEmail && !$hospitalAddress && !$hospitalDescription) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'At least one field must be provided.']);
}

try {
    $db = getDB();
    $hs = new HospitalSettingsService($db);
    $audit = new AdminAuditService($db, (int)$user['id']);

    // Get current settings for audit
    $oldSettings = $hs->getSettings();

    // Check for schedule conflicts if changing hours (unless confirmed)
    $confirmed = $input['confirmed'] ?? false;
    
    if ($openTime && $closeTime && !$confirmed) {
        $conflicts = $hs->checkScheduleConflicts($openTime, $closeTime);
        if (!empty($conflicts)) {
            $conflictCount = count($conflicts);
            $conflictDetails = array_slice($conflicts, 0, 5); // Return first 5 conflicts
            
            jsonResponse(false, [
                'message' => "Changing appointment hours would affect {$conflictCount} doctor schedule(s). Please review and confirm.",
                'conflicts' => $conflictDetails,
                'conflict_count' => $conflictCount,
                'requires_confirmation' => true
            ]);
        }
    }

    $db->beginTransaction();

    try {
        // Build update data
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

        // Get new settings for audit
        $newSettings = $hs->getSettings();

        // Log the change
        $audit->logHospitalSettingsChange($oldSettings, $newSettings);

        $db->commit();

        jsonResponse(true, ['message' => 'Hospital settings updated successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Update Hospital Settings Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}
