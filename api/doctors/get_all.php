<?php
/**
 * HealthBridge — Admin: Get All Doctors
 * Returns all doctor user accounts with their status.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';

requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT u.id,u.name,u.email,u.phone,u.is_active,u.created_at,
                    dp.specialty,COALESCE(rs.average_rating,0) AS rating,
                    dp.profile_icon AS emoji,dp.department_id,
                    dp.experience_years AS exp,
                    COALESCE(dss.is_available,0) AS available,
                    dep.name AS department_name
             FROM users u
             LEFT JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
             LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id=u.id
             LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=u.id
             LEFT JOIN departments dep ON dep.id=dp.department_id
             WHERE u.role="doctor"
             ORDER BY u.created_at DESC'
    );
    $stmt->execute();

    jsonResponse(true, ['doctors' => $stmt->fetchAll()]);

} catch (Throwable $e) {
    error_log('Get All Doctors Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load doctors.']);
}

