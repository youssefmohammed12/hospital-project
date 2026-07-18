<?php
/**
 * HealthBridge — Update Medical Record
 * Updates a patient's medical record information.
 * Notifies the patient when changes are made.
 *
 * Permissions:
 *   - Doctor: can update records of patients they have treated
 *   - Admin: can update any patient's record (only admin fields with audit trail)
 *   - Patient: CANNOT edit
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];
$currentName   = $user['name'] ?? 'A staff member';

// Only doctors and admins can update
if (!in_array($currentRole, ['doctor', 'admin'])) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors and admins can update medical records.']);
}

$input = getJsonInput();
$patientId = (int)($input['patient_id'] ?? 0);
$reason = trim($input['reason'] ?? '');

if (!$patientId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

// For admin corrections, reason is mandatory
if ($currentRole === 'admin' && !empty($input['blood_type'] ?? $input['emergency_contact_name'] ?? $input['emergency_contact_phone'])) {
    if (empty($reason)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Reason is required for administrative corrections.']);
    }
}

try {
    $db = getDB();
    $db->beginTransaction();

    // ── Authorization: Doctor must have treated this patient ──
    if ($currentRole === 'doctor') {
        $authStmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM appointments
             WHERE doctor_id = ? AND user_id = ? AND status IN ('Confirmed', 'Cancelled')
             LIMIT 1"
        );
        $authStmt->execute([$currentUserId, $patientId]);
        if ((int)$authStmt->fetch()['cnt'] === 0) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only update records of patients you have treated.']);
        }
    }

    // ── Check if medical record exists and get current values ──
    $checkStmt = $db->prepare("SELECT * FROM medical_records WHERE patient_id = ? LIMIT 1");
    $checkStmt->execute([$patientId]);
    $existing = $checkStmt->fetch();

    if (!$existing) {
        // Auto-create if missing
        $insertStmt = $db->prepare("INSERT INTO medical_records (patient_id) VALUES (?)");
        $insertStmt->execute([$patientId]);
        $existing = [];
    }

    // ── Admin-only fields (require audit trail) ──
    $adminFields = ['blood_type', 'emergency_contact_name', 'emergency_contact_phone'];
    $auditEntries = [];

    // ── Build update fields (only provided ones) ──
    $allowedFields = [
        'blood_type', 'height_cm', 'weight_kg', 'date_of_birth', 'gender',
        'allergies', 'chronic_diseases', 'current_medications',
        'previous_surgeries', 'family_history',
        'emergency_contact_name', 'emergency_contact_rel', 'emergency_contact_phone',
        'medical_notes',
    ];

    // Human-readable field labels
    $fieldLabels = [
        'blood_type'              => 'Blood type',
        'height_cm'               => 'Height (cm)',
        'weight_kg'               => 'Weight (kg)',
        'date_of_birth'           => 'Date of birth',
        'gender'                  => 'Gender',
        'allergies'               => 'Allergies',
        'chronic_diseases'        => 'Chronic diseases',
        'current_medications'     => 'Current medications',
        'previous_surgeries'      => 'Previous surgeries',
        'family_history'          => 'Family history',
        'emergency_contact_name'  => 'Emergency contact name',
        'emergency_contact_rel'   => 'Emergency contact relationship',
        'emergency_contact_phone' => 'Emergency contact phone',
        'medical_notes'           => 'Medical notes',
    ];

    $updates = [];
    $params = [];
    $hasActualChange = false;
    $oldValues = [];
    $newValues = [];
    $changeDescriptions = [];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $newValue = $input[$field] !== '' ? $input[$field] : null;
            $oldValue = $existing[$field] ?? null;

            // Only include if the value actually changed
            if ($newValue !== $oldValue) {
                $updates[] = "{$field} = ?";
                $params[] = $newValue;
                $hasActualChange = true;

                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;

                $label = $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field));
                $oldDisplay = $oldValue !== null && $oldValue !== '' ? $oldValue : 'None';
                $newDisplay = $newValue !== null && $newValue !== '' ? $newValue : 'None';
                $changeDescriptions[] = "{$label}: {$oldDisplay} → {$newDisplay}";

                // Track admin-only fields for medical_record_audit table
                if ($currentRole === 'admin' && in_array($field, $adminFields)) {
                    $auditEntries[] = [
                        'field' => $field,
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
        }
    }

    if (!$hasActualChange) {
        $db->rollBack();
        jsonResponse(true, ['message' => 'No changes detected.']);
    }

    $params[] = $patientId;
    $sql = "UPDATE medical_records SET " . implode(', ', $updates) . " WHERE patient_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // ── Log audit entries for admin corrections (medical_record_audit table) ──
    if ($currentRole === 'admin' && !empty($auditEntries)) {
        $auditStmt = $db->prepare("
            INSERT INTO medical_record_audit
            (patient_id, admin_id, field_name, old_value, new_value, reason)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($auditEntries as $entry) {
            $auditStmt->execute([
                $patientId,
                $currentUserId,
                $entry['field'],
                $entry['old'],
                $entry['new'],
                $reason
            ]);
        }
    }

    // ── Log to universal audit trail (inside transaction so it rolls back on failure) ──
    $description = 'Medical record updated: ' . implode(', ', $changeDescriptions);
    $audit = new AuditService($db, $currentUserId, $currentRole);
    $audit->log(
        'update',
        'patient',
        $patientId,
        $oldValues,
        $newValues,
        $description,
        $patientId,
        ($currentRole === 'doctor' ? $currentUserId : null)
    );

    // ── Notify the patient ──
    $ns = new NotificationService($db);
    if ($currentRole === 'doctor') {
        $ns->create(
            $patientId,
            NotificationService::TYPE_MEDICAL_RECORD_UPDATED,
            'Medical Record Updated',
            "{$currentName} updated your medical record. Review the latest information in your Medical Record.",
            'medical_record',
            $patientId
        );
    } else {
        $ns->create(
            $patientId,
            NotificationService::TYPE_MEDICAL_RECORD_UPDATED,
            'Medical Record Updated',
            'Your medical record was updated by the hospital administration. You can review the changes in your Medical Record.',
            'medical_record',
            $patientId
        );
    }

    $db->commit();

    jsonResponse(true, ['message' => 'Medical record updated successfully.']);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Update Medical Record Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update medical record. Error: ' . $e->getMessage()]);
}

