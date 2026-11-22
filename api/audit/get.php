<?php
/**
 * HealthBridge — Get Admin Audit Log
 *
 * Returns paginated, filterable audit log entries.
 * Admin-only endpoint. Requires authentication.
 *
 * GET /api/audit/get.php
 *   ?search=keyword
 *   &action=create|update|delete|activate|deactivate|reassign
 *   &entity_type=department|hospital_settings|doctor|patient
 *   &entity_id=3
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

// Central authentication also verifies account status and the session version.
$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
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

    if (isset($_GET['entity_id']) && (int)$_GET['entity_id'] > 0) {
        $filters['entity_id'] = (int)$_GET['entity_id'];
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

    $auditSchema = AdminAuditService::schemaFromEnvironment();
    $audit = new AdminAuditService($db, (int)$user['id'], $auditSchema);
    $result = $audit->getAuditLog($filters);

    // Keep filter-option DTOs stable while reading the schema-specific actor columns.
    if ($auditSchema === AuditService::SCHEMA_V2) {
        $adminSql = "
            SELECT DISTINCT a.actor_user_id AS actor_id,
                   COALESCE(u.name, CONCAT('Deleted ', UPPER(SUBSTRING(a.actor_role_snapshot, 1, 1)), SUBSTRING(a.actor_role_snapshot, 2))) AS name
            FROM audit_events a
            LEFT JOIN users u ON a.actor_user_id = u.id
            ORDER BY name ASC
        ";
    } else {
        $adminSql = "
            SELECT DISTINCT a.actor_id,
                   COALESCE(u.name, CONCAT('Deleted ', UPPER(SUBSTRING(a.actor_role, 1, 1)), SUBSTRING(a.actor_role, 2))) AS name
            FROM admin_audit a
            LEFT JOIN users u ON a.actor_id = u.id
            ORDER BY name ASC
        ";
    }
    $admins = $db->query($adminSql)->fetchAll(PDO::FETCH_ASSOC);

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

} catch (Throwable $e) {
    error_log('Get Audit Log Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load audit log.']);
}

