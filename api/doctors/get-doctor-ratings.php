<?php
/**
 * HealthBridge — Get Doctor's Ratings & Reviews
 * Returns ratings for the logged-in doctor.
 * Anonymized: hides patient name, email, and ID.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/RatingRepository.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$requestedDoctorId = $_GET['doctor_id'] ?? null;
$parsedRequestedDoctorId = $requestedDoctorId !== null
    ? parsePositiveInt($requestedDoctorId)
    : null;

if (($user['role'] ?? '') === 'admin') {
    if ($parsedRequestedDoctorId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'A valid doctor ID is required.']);
    }
    $doctorId = $parsedRequestedDoctorId;
} elseif (($user['role'] ?? '') === 'doctor') {
    $doctorId = (int)$user['id'];
    if ($requestedDoctorId !== null && $parsedRequestedDoctorId !== $doctorId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Doctors can only view their own ratings.']);
    }
} else {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Doctor ratings are not available to this account.']);
}

try {
    $db = getDB();

    $doctorStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
    $doctorStmt->execute([$doctorId]);
    if (!$doctorStmt->fetchColumn()) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }

    $ratings = (new RatingRepository($db))->getRatingsForDoctor($doctorId);
    
    // Add appointment_time_range to each rating — prefer stored value
    foreach ($ratings as &$rating) {
        $sourceTime = (string)($rating['source_time_snapshot'] ?? '');
        if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$rating['appt_time'])) {
            $rating['appt_time'] = $sourceTime;
            $rangeSnapshot = $rating['appointment_time_range'] ?? null;
            $rating['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                ? $rangeSnapshot
                : AppointmentTime::range((string)$rating['appt_time'], max(1, (int)($rating['duration_minutes'] ?? 30)));
        } else {
            $rating['appointment_time_range'] = AppointmentTime::range(
                (string)$rating['appt_time'],
                max(1, (int)($rating['duration_minutes'] ?? 30))
            );
        }
    }
    unset($rating);

    jsonResponse(true, ['ratings' => $ratings]);

} catch (Throwable $e) {
    error_log('Get Doctor Ratings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load ratings.']);
}

