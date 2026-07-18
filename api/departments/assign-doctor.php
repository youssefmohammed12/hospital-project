<?php
/**
 * HealthBridge — Assign Doctor to Department
 * Assigns or reassigns a doctor to a department with audit logging.
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$input = getJsonInput();

// The frontend sends doctor_id as users.id (canonical doctor identifier)
$doctorUserId = (int)($input['doctor_id'] ?? 0);
$departmentId = (int)($input['department_id'] ?? 0);

if ($doctorUserId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID is required.']);
}

if ($departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);
    $audit = new AdminAuditService($db, (int)$user['id']);

    // Get doctor info for audit — lookup by user_id since frontend sends users.id
    $stmt = $db->prepare("SELECT id, name, department_id, user_id FROM doctors WHERE user_id = ?");
    $stmt->execute([$doctorUserId]);
    $doctor = $stmt->fetch();
    
    if (!$doctor) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }
    
    // Use the actual doctors.id for the assignment, users.id for audit entity_id
    $doctorTableId = (int)$doctor['id'];
    $oldDepartmentId = $doctor['department_id'];

    $db->beginTransaction();

    try {
        $ds->assignDoctorToDepartment($doctorTableId, $departmentId);
        
        // Sync specialty with department name for backward compatibility
        $deptStmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
        $deptStmt->execute([$departmentId]);
        $department = $deptStmt->fetch();
        if ($department) {
            $syncStmt = $db->prepare('UPDATE doctors SET specialty = ? WHERE id = ?');
            $syncStmt->execute([$department['name'], $doctorTableId]);
        }
        
        // Log the reassignment using the specialized method that resolves department names
        $audit->logDoctorReassignment($doctorUserId, $doctor['name'], $oldDepartmentId, $departmentId);

        $db->commit();

        jsonResponse(true, ['message' => 'Doctor assigned to department successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Assign Doctor Department Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

