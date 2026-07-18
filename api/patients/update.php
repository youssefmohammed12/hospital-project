<?php
/**
 * HealthBridge — Update Patient
 * Dedicated endpoint for admin to update patient user details.
 * Does NOT use update_settings.php (which only updates the current user).
 *
 * Updates:
 *   Name, Email, Phone, Gender, Date of Birth, Account Status, Password (optional)
 *
 * Permissions: Admin only
 * Reuses: update_medical_record.php logic for medical fields
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';

header('Content-Type: application/json');

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$input = getJsonInput();
$patientId = (int)($input['patient_id'] ?? 0);

if (!$patientId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();

    // Verify patient exists; also JOIN medical_records to capture old gender/date_of_birth
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.email, u.phone, u.is_active,
               mr.gender, mr.date_of_birth
        FROM users u
        LEFT JOIN medical_records mr ON u.id = mr.patient_id
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    // ── Update user fields ──
    $name  = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';

    if (!$name || !$email) {
        jsonResponse(false, ['message' => 'Name and email are required.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, ['message' => 'Invalid email address.']);
    }

    // Check duplicate email
    $check = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $check->execute([$email, $patientId]);
    if ($check->fetch()) {
        jsonResponse(false, ['message' => 'This email is already in use by another account.']);
    }

    $userUpdates = ['name = ?', 'email = ?', 'phone = ?'];
    $userParams = [$name, $email, $phone ?: null];

    // Optional password update
    if (!empty($password)) {
        if (strlen($password) < 6) {
            jsonResponse(false, ['message' => 'Password must be at least 6 characters.']);
        }
        $userUpdates[] = 'password = ?';
        $userParams[] = password_hash($password, PASSWORD_BCRYPT);
    }

    $userUpdates[] = 'updated_at = NOW()';
    $userParams[] = $patientId;

    $db->prepare('UPDATE users SET ' . implode(', ', $userUpdates) . ' WHERE id = ?')
       ->execute($userParams);

    // ── Update medical record fields (gender, date_of_birth) ──
    $gender = $input['gender'] ?? null;
    $dob    = $input['date_of_birth'] ?? null;

    // Ensure medical record exists
    $mrStmt = $db->prepare("SELECT id FROM medical_records WHERE patient_id = ? LIMIT 1");
    $mrStmt->execute([$patientId]);
    $mrRow = $mrStmt->fetch();

    if (!$mrRow) {
        $db->prepare("INSERT INTO medical_records (patient_id) VALUES (?)")->execute([$patientId]);
    }

    $mrUpdates = [];
    $mrParams  = [];

    if ($gender !== null && $gender !== '') {
        $mrUpdates[] = 'gender = ?';
        $mrParams[]  = $gender;
    }
    if ($dob !== null && $dob !== '') {
        $mrUpdates[] = 'date_of_birth = ?';
        $mrParams[]  = $dob;
    }

    if (!empty($mrUpdates)) {
        $mrParams[] = $patientId;
        $db->prepare('UPDATE medical_records SET ' . implode(', ', $mrUpdates) . ' WHERE patient_id = ?')
           ->execute($mrParams);
    }

    // ── Toggle account status if changed ──
    $newStatus = null;
    if (isset($input['is_active'])) {
        $newStatus = $input['is_active'] ? 1 : 0;
        $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')
           ->execute([$newStatus, $patientId]);
    }

    // ── Build change description for audit ──
    $changes = [];

    if ($patient['name'] !== $name) {
        $changes[] = "Name: {$patient['name']} → {$name}";
    }
    if ($patient['email'] !== $email) {
        $changes[] = "Email: {$patient['email']} → {$email}";
    }
    $oldPhone = $patient['phone'] ?? 'None';
    $newPhone = $phone ?: 'None';
    if ($oldPhone !== $newPhone) {
        $changes[] = "Phone: {$oldPhone} → {$newPhone}";
    }

    // Gender comparison (medical_records)
    $oldGender = $patient['gender'] ?? null;
    if ($gender !== null && $gender !== '' && $gender !== $oldGender) {
        $oldGenderLabel = $oldGender ? ucfirst($oldGender) : 'Not specified';
        $newGenderLabel = ucfirst($gender);
        $changes[] = "Gender: {$oldGenderLabel} → {$newGenderLabel}";
    }

    // Date of birth comparison (medical_records)
    $oldDob = $patient['date_of_birth'] ?? null;
    if ($dob !== null && $dob !== '' && $dob !== $oldDob) {
        $oldDobLabel = $oldDob ? date('M j, Y', strtotime($oldDob)) : 'Not specified';
        $newDobLabel = date('M j, Y', strtotime($dob));
        $changes[] = "Date of birth: {$oldDobLabel} → {$newDobLabel}";
    }

    if ($newStatus !== null && (int)$patient['is_active'] !== $newStatus) {
        $changes[] = 'Status: ' . ($patient['is_active'] ? 'Active' : 'Inactive') . ' → ' . ($newStatus ? 'Active' : 'Inactive');
    }
    if (!empty($password)) {
        $changes[] = 'Password reset by administrator';
    }

    // ── Notify patient of profile update ──
    $ns = new NotificationService($db);
    $ns->create(
        $patientId,
        NotificationService::TYPE_PROFILE_UPDATED,
        'Profile Updated',
        'Your profile information has been updated by an administrator.',
        'user',
        $patientId
    );

    // ── Log audit (only if something actually changed) ──
    if (!empty($changes)) {
        $audit = new AuditService($db, (int)$user['id'], $user['role']);
        $audit->log(
            'update',
            'patient',
            $patientId,
            null,
            null,
            'Patient profile updated: ' . implode(', ', $changes),
            $patientId,
            null
        );
    }

    jsonResponse(true, ['message' => 'Patient updated successfully!']);

} catch (Exception $e) {
    error_log('Update Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update patient.']);
}
