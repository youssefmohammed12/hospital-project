<?php
/**
 * HealthBridge — Admin: Add Doctor
 * Creates a new doctor account with department assignment.
 * Uses DepartmentService for authoritative department_id updates.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$departmentId = (int)($input['department_id'] ?? 0);

if (!$name || !$email || !$password) {
    jsonResponse(false, ['message' => 'Name, email, and password are required.']);
}

if (strlen($password) < 6) {
    jsonResponse(false, ['message' => 'Password must be at least 6 characters.']);
}

if ($departmentId <= 0) {
    jsonResponse(false, ['message' => 'Department is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);

    // Check duplicate email
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(false, ['message' => 'A user with this email already exists.']);
    }

    // Verify department exists and is active
    $department = $ds->getDepartmentById($departmentId);
    if (!$department) {
        jsonResponse(false, ['message' => 'Department not found.']);
    }
    if ($department['status'] !== 'active') {
        jsonResponse(false, ['message' => 'Cannot assign doctors to inactive departments.']);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $db->beginTransaction();

    try {
        // Create user account
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'doctor', NOW())");
        $stmt->execute([$name, $email, $hashedPassword]);
        $userId = (int)$db->lastInsertId();

        // Create doctor profile with department assignment
        $stmt = $db->prepare("INSERT INTO doctors (user_id, name, specialty, rating, exp, available, department_id) VALUES (?, ?, ?, 4.5, 5, 1, ?)");
        $stmt->execute([$userId, $name, $department['name'], $departmentId]);

        // Create default preferences
        $stmt = $db->prepare("INSERT IGNORE INTO user_preferences (user_id) VALUES (?)");
        $stmt->execute([$userId]);

        // Notify all admins
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role = 'admin'");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll();

        $ns = new NotificationService($db);
        foreach ($admins as $admin) {
            $ns->create(
                (int)$admin['id'],
                NotificationService::TYPE_NEW_DOCTOR,
                'New Doctor Registered',
                "Dr. {$name} ({$email}) has been registered in the {$department['name']} department.",
                'doctor',
                $userId
            );
        }

        // Log the action
        try {
            $audit = new AdminAuditService($db, (int)$user['id']);
            $audit->log('create', 'doctor', $userId, null, [
                'name' => $name,
                'email' => $email,
                'department_id' => $departmentId,
                'department' => $department['name'],
            ]);
        } catch (Exception $auditErr) {
            error_log('Audit log error (add_doctor): ' . $auditErr->getMessage());
        }

        $db->commit();
        jsonResponse(true, ['message' => 'Doctor added successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Add Doctor Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to add doctor.']);
}
