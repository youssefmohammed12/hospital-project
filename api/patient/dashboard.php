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
$userId = (int)$user['id'];
$role = $user['role'] ?? '';

// Only patients can access their own dashboard
if ($role !== 'patient') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Patient access required.']);
}

// ── Parse optional input ─────────────────────────────────
$input = getJsonInput();
$searchQuery = trim($input['search'] ?? '');

// ── Response Caching Headers ─────────────────────────────
header('Cache-Control: private, max-age=30, must-revalidate');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 30) . ' GMT');

// ── Execute with detailed error catching ─────────────────
try {
    $db = getDB();
    $portal = new PatientPortalService($db);

    // If search query is provided, only return search results
    if (!empty($searchQuery)) {
        $searchResults = $portal->searchAll($userId, $searchQuery);
        jsonResponse(true, ['data' => [
            'search' => $searchResults,
        ]]);
        return;
    }

    // Return complete dashboard data (both preview keys for dashboard and full section keys for portal pages)
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
