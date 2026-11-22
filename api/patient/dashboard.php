<?php
/**
 * HealthBridge — Patient Dashboard API (Lightweight Preview)
 *
 * Returns compact preview data for the single-screen dashboard.
 * Each widget shows only 3-5 items, not full lists.
 *
 * Endpoint: POST /api/patient/dashboard.php
 * Auth:     Requires patient session
 * Rate:     Frontend should poll every 30-60 seconds for real-time updates
 *
 * Response:
 *   success: true
 *   data: {
 *     overview: { ... },
 *     health_snapshot: { ... },
 *     recent_activity: [3 items],
 *     recent_notifications: [5 items],
 *     latest_prescription: { ... },
 *     favorite_doctors: [3 items],
 *     recent_downloads: [3 items],
 *     insights_preview: { ... },
 *     health_alerts: [ ... ],
 *     profile_completion: { ... }
 *   }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';

// ── Authentication & Authorization ────────────────────────
$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = assertRole($user, 'patient');
$userId = (int)$user['id'];

// ── Parse optional input ─────────────────────────────────
$input = getJsonInput();
$searchValue = $input['search'] ?? '';

if (!is_string($searchValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Search query must be text.']);
}

$searchQuery = trim($searchValue);

// Patient dashboard data includes private clinical and account information.
// Keep the shared Cache-Control: no-store policy from the auth bootstrap.

// ── Execute with detailed error catching ─────────────────
try {
    $db = getDB();
    $portal = new PatientPortalService($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PORTAL_SCHEMA'));

    // If search query is provided, only return search results
    if (!empty($searchQuery)) {
        $searchResults = $portal->searchAll($userId, $searchQuery);
        jsonResponse(true, ['data' => [
            'search' => $searchResults,
        ]]);
        return;
    }

    // Page controllers may request a bounded set of canonical portal sections.
    // Requests without "sections" retain the complete legacy response below.
    if (array_key_exists('sections', $input)) {
        if (!is_array($input['sections'])) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Invalid section selection.']);
        }

        try {
            $selectedData = $portal->getSelected($userId, $input['sections']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Invalid section selection.']);
        }

        jsonResponse(true, ['data' => $selectedData]);
        return;
    }

    // Preserve the complete legacy dashboard payload for existing callers.
    $allData = $portal->getAll($userId);
    
    jsonResponse(true, ['data' => array_merge($allData, [
        'recent_activity'       => array_reverse(array_slice($allData['medical_timeline'], -3, 3)),
        'recent_notifications'  => $allData['notifications'],
        'latest_prescription'   => $allData['prescriptions']['prescriptions'][0] ?? null,
        'favorite_doctors'      => $allData['favorites']['most_visited'],
        'recent_downloads'      => [
            'prescriptions' => array_slice($allData['downloads']['prescriptions'], 0, 3),
            'visit_summaries' => array_slice($allData['downloads']['visit_summaries'], 0, 3),
        ],
        'insights_preview'      => [
            'monthly_visits' => array_slice($allData['insights']['monthly_visits'], -6, 6),
            'department_distribution' => $allData['insights']['department_distribution'],
        ],
    ])]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "file" => basename($e->getFile()),
        "line" => $e->getLine()
    ]);
    exit;
}
