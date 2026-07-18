<?php
/**
 * HealthBridge — Get Appointment Rating
 * Returns the logged-in user's rating for a specific appointment.
 * GET ?appointmentId={id}
 */

require_once __DIR__ . '/../../includes/auth.php';

$userId = requireAuth()['id'];

$appointmentId = (int)($_GET['appointmentId'] ?? 0);
if (!$appointmentId) {
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, stars, review, created_at FROM ratings WHERE appointment_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$appointmentId, $userId]);
    $rating = $stmt->fetch();

    jsonResponse(true, ['rating' => $rating]); // null if not rated yet

} catch (Exception $e) {
    error_log('Get Rating Error: ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Failed to retrieve rating.']);
}

