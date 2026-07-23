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

// ── Execute ──────────────────────────────────────────────
withDB(function (PDO $db) use ($userId, $searchQuery) {
    $portal = new PatientPortalService($db);

    // If search query is provided, only return search results
    if (!empty($searchQuery)) {
        $searchResults = $portal->searchAll($userId, $searchQuery);
        return ['data' => [
            'search' => $searchResults,
        ]];
    }

    // Return lightweight dashboard preview data
    return ['data' => [
        'overview'              => $portal->getOverview($userId),
        'health_snapshot'       => $portal->getHealthSnapshot($userId),
        'recent_activity'       => array_slice($portal->getMedicalTimeline($userId), -3, 3, true),
        'recent_notifications'  => $portal->getNotifications($userId, 5),
        'latest_prescription'   => $portal->getPrescriptions($userId)['prescriptions'][0] ?? null,
        'favorite_doctors'      => $portal->getFavorites($userId)['most_visited'],
        'recent_downloads'      => [
            'prescriptions' => array_slice($portal->getDownloads($userId)['prescriptions'], 0, 3),
            'visit_summaries' => array_slice($portal->getDownloads($userId)['visit_summaries'], 0, 3),
        ],
        'insights_preview'      => [
            'monthly_visits' => array_slice($portal->getHealthInsights($userId)['monthly_visits'], -6, 6),
            'department_distribution' => $portal->getHealthInsights($userId)['department_distribution'],
        ],
        'health_alerts'          => $portal->getHealthAlerts($userId),
        'profile_completion'     => $portal->getProfileCompletion($userId),
    ]];
}, 'Failed to load dashboard data.');
