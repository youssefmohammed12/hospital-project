<?php
/**
 * HealthBridge — Get Appointment Rating
 * Returns the logged-in user's rating for a specific appointment.
 * GET ?appointmentId={id}
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/RatingRepository.php';

$userId = (int)requireRole('patient')['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$appointmentIdValue = $_GET['appointmentId'] ?? null;
$appointmentId = is_string($appointmentIdValue) && ctype_digit($appointmentIdValue)
    ? (int)$appointmentIdValue
    : 0;
if ($appointmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

try {
    $db = getDB();
    $rating = (new RatingRepository($db))->getAppointmentRating($appointmentId, $userId);

    jsonResponse(true, ['rating' => $rating]); // null if not rated yet

} catch (Throwable $e) {
    error_log('Get Rating Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to retrieve rating.']);
}

