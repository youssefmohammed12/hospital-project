<?php
/**
 * HealthBridge — Get All Doctors (Public)
 * Returns the list of doctors with their computed average rating.
 * Availability is read from doctor_schedule_settings (single source of truth).
 * GET only; no authentication required.
 *
 * Phase 6.1: Now JOINs with departments table to return department_name
 * and filters out doctors assigned to inactive departments for public view.
 *
 * Architecture:
 * - doctors.department_id is the authoritative doctor-to-department relationship.
 * - department_name is derived from departments.name via department_id.
 * - specialty is preserved for backward-compatible display only.
 * - Doctors assigned to inactive departments are excluded from public results.
 * - Doctors with no department_id or assigned to a non-existent department
 *   are still shown using their specialty as fallback.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT dp.doctor_user_id AS id,
                    dp.doctor_user_id AS user_id,
                    u.name,dp.specialty,
                    dp.experience_years AS exp,
                    dp.profile_icon AS emoji,
                    dp.department_id,
                    COALESCE(dep.name,dp.specialty) AS department_name,
                    COALESCE(r.average_rating,0) AS rating,
                    COALESCE(r.rating_count,0) AS rating_count,
                    COALESCE(dss.is_available,0) AS available
             FROM doctor_profiles dp
             INNER JOIN users u
               ON u.id=dp.doctor_user_id AND u.role='doctor' AND u.is_active=1
             LEFT JOIN departments dep ON dep.id=dp.department_id
             LEFT JOIN v_doctor_rating_summary r ON r.doctor_user_id=dp.doctor_user_id
             LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=dp.doctor_user_id
             WHERE dp.profile_visible=1
               AND (dp.department_id IS NULL OR dep.status='active' OR dep.id IS NULL)
             ORDER BY rating DESC,u.name ASC"
    );
    $doctors = $stmt->fetchAll();
    jsonResponse(true, ['doctors' => $doctors]);
} catch (Throwable $e) {
    error_log('Get Doctors Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['doctors' => [], 'message' => 'Failed to load doctors.']);
}

