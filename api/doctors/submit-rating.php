<?php
/**
 * HealthBridge — Submit Rating
 * Patients may rate one completed appointment exactly once.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/RatingRepository.php';

header('Content-Type: application/json; charset=utf-8');

$patient = requireRole('patient');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$starsRaw = $input['stars'] ?? null;
$reviewRaw = $input['review'] ?? '';

if ($appointmentId === null || $appointmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid rating data.']);
}
if (!(is_int($starsRaw) || (is_string($starsRaw) && ctype_digit($starsRaw)))) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Rating must be between one and five stars.']);
}
$stars = (int)$starsRaw;
if ($stars < 1 || $stars > 5) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Rating must be between one and five stars.']);
}
if (!is_string($reviewRaw)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Review must be text.']);
}
$review = trim($reviewRaw);

try {
    $db = getDB();
    $ratings = new RatingRepository($db);
    $ratings->submitForCompletedAppointment(
        $appointmentId,
        (int)$patient['id'],
        $stars,
        $review
    );

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Submit Rating Outbox Dispatch Error: ' . get_class($notificationError));
    }

    jsonResponse(true, ['message' => 'Rating submitted successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Appointment not found.') {
        http_response_code(404);
    } elseif (str_contains($message, 'completed visit')) {
        http_response_code(409);
    } elseif (str_contains($message, 'already rated')) {
        http_response_code(409);
    } else {
        http_response_code(403);
    }
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (isDuplicateKeyViolation($e)) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'You have already rated this appointment.']);
    }
    error_log('Submit Rating Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to submit rating.']);
}
