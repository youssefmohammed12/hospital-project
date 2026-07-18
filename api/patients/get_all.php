<?php
/**
 * HealthBridge — Admin: Get All Patients
 * Returns all patient user accounts with their status.
 */

require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, email, phone, is_active, created_at
         FROM users WHERE role = "patient" ORDER BY created_at DESC'
    );
    $stmt->execute();

    jsonResponse(true, ['patients' => $stmt->fetchAll()]);

} catch (Exception $e) {
    error_log('Get Patients Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to retrieve patients.']);
}

