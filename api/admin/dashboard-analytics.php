<?php
/**
 * HealthBridge — Admin Dashboard Analytics API
 *
 * Single optimized endpoint returning all dashboard statistics
 * and chart data in one response.
 *
 * Requires: admin role
 * Method: GET
 *
 * Response:
 *   success: true
 *   data: {
 *     kpi: { ... },
 *     appointment_analytics: { ... },
 *     patient_analytics: { ... },
 *     doctor_analytics: { ... },
 *     department_analytics: [ ... ],
 *     recent_activity: [ ... ],
 *     system_status: { ... }
 *   }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DashboardAnalyticsService.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
    exit;
}

try {
    $db = getDB();
    $service = new DashboardAnalyticsService($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_ANALYTICS_SCHEMA'));
    $data = $service->getAll();

    jsonResponse(true, $data);
} catch (Exception $e) {
    error_log('Dashboard Analytics Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load dashboard analytics.']);
}