<?php
/**
 * HealthBridge — Get All Doctors (Public)
 * Returns the list of doctors with their computed average rating.
 * Availability is read from doctor_schedule_settings (single source of truth).
 * No authentication required.
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
try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT d.id, d.user_id, d.name, d.specialty, d.exp, d.emoji, d.department_id,
                COALESCE(dep.name, d.specialty) as department_name,
                COALESCE(ROUND(AVG(r.stars), 1), d.rating) as rating,
                COALESCE(dss.is_available, d.available) as available
         FROM doctors d
         LEFT JOIN ratings r ON d.user_id = r.doctor_id
         LEFT JOIN doctor_schedule_settings dss ON d.user_id = dss.doctor_id
         LEFT JOIN departments dep ON d.department_id = dep.id
         WHERE d.department_id IS NULL
            OR dep.status = 'active'
            OR dep.id IS NULL
         GROUP BY d.id, d.user_id, d.name, d.specialty, d.exp, d.available, d.emoji, d.rating, dss.is_available, d.department_id, dep.name, dep.status
         ORDER BY rating DESC, d.name ASC"
    );
    $doctors = $stmt->fetchAll();
    jsonResponse(true, ['doctors' => $doctors]);
} catch (Exception $e) {
    error_log('Get Doctors Error: ' . $e->getMessage());
    jsonResponse(false, ['doctors' => [], 'message' => 'Failed to load doctors.']);
}

