<?php
/**
 * HealthBridge — Get Departments
 * Returns list of departments for admin management or booking.
 * Admin-only for full details, public for active departments only.
 *
 * Phase 6.1: Public users (unauthenticated) can now retrieve active
 * departments for dynamic rendering on the doctors and home pages.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';

// Start session for auth check, but don't require it
$role = $_SESSION['role'] ?? '';

try {
    $db = getDB();
    $ds = new DepartmentService($db);

    // Admin: get all departments including inactive
    if ($role === 'admin') {
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        $departments = $ds->getAllDepartments($includeInactive);
        jsonResponse(true, ['departments' => $departments]);
    }

    // Doctors/Patients/Public (unauthenticated): get only active departments
    $departments = $ds->getActiveDepartmentsForBooking();
    jsonResponse(true, ['departments' => $departments]);

} catch (Exception $e) {
    error_log('Get Departments Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load departments.']);
}

