<?php
/**
 * HealthBridge — Get Admin Audit Log
 *
 * Returns paginated, filterable audit log entries.
 * Admin-only endpoint. Requires authentication.
 *
 * GET /php/get_audit_log.php
 *   ?search=keyword
 *   &action=create|update|delete|activate|deactivate|reassign
 *   &entity_type=department|hospital_settings|doctor|patient
 *   &patient_id=2
 *   &doctor_id=3
 *   &actor_id=1
 *   &actor_role=admin|doctor|patient
 *   &date_from=2026-01-01
 *   &date_to=2026-07-12
 *   &page=1
 *   &limit=20
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

// ── Require admin authentication ────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
    exit;
}

try {
    $db = getDB();

    // Build safe filters from GET params
    $filters = [];

    if (!empty($_GET['search'])) {
        $filters['search'] = trim($_GET['search']);
    }

    if (!empty($_GET['action'])) {
        $filters['action'] = trim($_GET['action']);
    }

    if (!empty($_GET['entity_type'])) {
        $filters['entity_type'] = trim($_GET['entity_type']);
    }

    if (!empty($_GET['actor_id'])) {
        $filters['actor_id'] = (int)$_GET['actor_id'];
    } elseif (!empty($_GET['admin_id'])) {
        $filters['actor_id'] = (int)$_GET['admin_id'];
    }

    if (!empty($_GET['patient_id'])) {
        $filters['patient_id'] = (int)$_GET['patient_id'];
    }

    if (!empty($_GET['doctor_id'])) {
        $filters['doctor_id'] = (int)$_GET['doctor_id'];
    }

    if (!empty($_GET['actor_role'])) {
        $filters['actor_role'] = trim($_GET['actor_role']);
    }

    if (!empty($_GET['date_from'])) {
        $d = trim($_GET['date_from']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $filters['date_from'] = $d;
        }
    }

    if (!empty($_GET['date_to'])) {
        $d = trim($_GET['date_to']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $filters['date_to'] = $d;
        }
    }

    // Pagination
    $filters['page'] = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $filters['limit'] = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;

    $audit = new AdminAuditService($db, (int)$_SESSION['user_id']);
    $result = $audit->getAuditLog($filters);

    // Also return filter options for dropdowns
    $adminStmt = $db->query("
        SELECT DISTINCT a.actor_id, COALESCE(u.name, CONCAT('Deleted ', UPPER(SUBSTRING(a.actor_role, 1, 1)), SUBSTRING(a.actor_role, 2))) as name
        FROM admin_audit a
        LEFT JOIN users u ON a.actor_id = u.id
        ORDER BY name ASC
    ");
    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, [
        'entries' => $result['entries'],
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'total_pages' => $result['total_pages'],
        'filter_options' => [
            'entity_types' => $audit->getDistinctEntityTypes(),
            'actions' => $audit->getDistinctActions(),
            'admins' => $admins,
        ],
    ]);

} catch (Exception $e) {
    error_log('Get Audit Log Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load audit log.']);
}

