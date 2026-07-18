<?php
/**
 * HealthBridge — Admin: Get All Doctors
 * Returns all doctor user accounts with their status.
 */

require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.created_at,
                d.specialty, d.rating, d.emoji, d.department_id
         FROM users u
         LEFT JOIN doctors d ON u.id = d.user_id
         WHERE u.role = "doctor"
         ORDER BY u.created_at DESC'
    );
    $stmt->execute();

    jsonResponse(true, ['doctors' => $stmt->fetchAll()]);

} catch (Exception $e) {
    error_log('Get All Doctors Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load doctors.']);
}

