<?php
/**
 * HealthBridge — Admin: Update Doctor Details
 * Updates a doctor's personal info, email, phone, and department assignment.
 * Uses DepartmentService for authoritative department_id updates.
 * POST { id, name, email, phone?, department_id? }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';
requireRole('admin');

$data = getJsonInput();
$id = (int)($data['id'] ?? 0);
$name = sanitizeString($data['name'] ?? '', 100);
$email = sanitizeString($data['email'] ?? '', 150);
$phone = sanitizeString($data['phone'] ?? '', 20);
$departmentId = (int)($data['department_id'] ?? 0);

if (!$id || !$name || !$email) {
    jsonResponse(false, ['message' => 'ID, name, and email are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, ['message' => 'Invalid email address.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);
    $audit = new AdminAuditService($db, (int)$_SESSION['user_id']);

    // Check duplicate email for other users
    $check = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $check->execute([$email, $id]);
    if ($check->fetch()) {
        jsonResponse(false, ['message' => 'Another user with this email already exists.']);
    }

    // Get doctor info for audit and to find doctors.id from users.id
    $profileCheck = $db->prepare('SELECT id as doctor_id, name as doctor_name, department_id FROM doctors WHERE user_id = ? LIMIT 1');
    $profileCheck->execute([$id]);
    $doctor = $profileCheck->fetch();

    if (!$doctor) {
        jsonResponse(false, ['message' => 'Doctor profile not found.']);
    }

    // Get old user details for comparison
    $oldUserStmt = $db->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
    $oldUserStmt->execute([$id]);
    $oldUser = $oldUserStmt->fetch();

    $doctorId = $doctor['doctor_id']; // doctors.id for profile lookup
    $doctorUserId = $id; // users.id for audit entity_id
    $oldDepartmentId = $doctor['department_id'];

    $db->beginTransaction();

    try {
        // Compare changes and build description
        $changes = [];
        if ($oldUser) {
            if ($oldUser['name'] !== $name) {
                $changes[] = "Name: {$oldUser['name']} → {$name}";
            }
            if ($oldUser['email'] !== $email) {
                $changes[] = "Email: {$oldUser['email']} → {$email}";
            }
            $oldPhone = $oldUser['phone'] ?? 'None';
            $newPhone = $phone ?: 'None';
            if ($oldPhone !== $newPhone) {
                $changes[] = "Phone: {$oldPhone} → {$newPhone}";
            }
        }

        // Update users table
        $db->prepare(
            'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = "doctor"'
        )->execute([$name, $email, $phone ?: null, $id]);

        // Update doctors table name
        $db->prepare(
            'UPDATE doctors SET name = ? WHERE id = ?'
        )->execute([$name, $doctorId]);

        // Log profile details changes if any actual change occurred
        if (!empty($changes)) {
            $description = "Profile updated: " . implode(', ', $changes);
            $audit->log('update', 'doctor', $doctorUserId, $oldUser, ['name' => $name, 'email' => $email, 'phone' => $phone ?: null], $description, null, $doctorUserId);
        }

        // Update department assignment if changed
        if ($departmentId > 0 && $departmentId != $oldDepartmentId) {
            $ds->assignDoctorToDepartment($doctorId, $departmentId);

            // Get department name for specialty sync
            $deptStmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
            $deptStmt->execute([$departmentId]);
            $department = $deptStmt->fetch();

            if ($department) {
                // Sync specialty with department name for backward compatibility
                $syncStmt = $db->prepare(
                    'UPDATE doctors SET specialty = ? WHERE id = ?'
                );
                $syncStmt->execute([$department['name'], $doctorId]);
            }

            // Log the reassignment
            $audit->logDoctorReassignment($doctorUserId, $doctor['doctor_name'], $oldDepartmentId, $departmentId);
        }

        $db->commit();
        jsonResponse(true, ['message' => 'Doctor details updated successfully!']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Update Doctor Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

