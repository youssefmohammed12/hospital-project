<?php
/**
 * HealthBridge — Get All Doctor Ratings (Admin Only)
 * Returns all ratings/reviews in the system with full identifying details.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/RatingRepository.php';

requireRole('admin');
$schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_RATINGS_SCHEMA');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

try {
    $db = getDB();
    $ratings = (new RatingRepository($db, $schema))->getAllRatings();

    jsonResponse(true, ['ratings' => $ratings]);

} catch (Throwable $e) {
    error_log('Get Admin Ratings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load system ratings.']);
}

