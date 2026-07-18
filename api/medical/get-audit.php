<?php
/**
 * HealthBridge — Get Medical Record Audit History (Phase 4.2.2)
 * 
 * Retrieves audit history for a patient's medical record corrections.
 * 
 * GET /php/get_medical_audit.php?patient_id=123
 */

require_once __DIR__ . '/../../includes/auth.php';

// Only admins can view audit history
$user = requireRole('admin');

$patient_id = intval($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Patient ID is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            mra.id,
            mra.field_name,
            mra.old_value,
            mra.new_value,
            mra.reason,
            mra.created_at,
            u.name as admin_name
        FROM medical_record_audit mra
        JOIN users u ON mra.admin_id = u.id
        WHERE mra.patient_id = ?
        ORDER BY mra.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $audit_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'audit_entries' => $audit_entries
    ]);
} catch (PDOException $e) {
    error_log("Audit retrieval error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve audit history.']);
}


