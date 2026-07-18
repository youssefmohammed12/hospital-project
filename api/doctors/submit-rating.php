<?php
/**
 * HealthBridge — Submit Rating
 * Allows a patient to rate a doctor after an appointment.
 * Notifies the doctor and admin.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$input = getJsonInput();

$appointmentId = (int)($input['appointment_id'] ?? 0);
$stars = (int)($input['stars'] ?? 0);
$review = trim($input['review'] ?? '');

if ($appointmentId <= 0 || $stars < 1 || $stars > 5) {
    jsonResponse(false, ['message' => 'Invalid rating data.']);
}

try {
    $db = getDB();

    // Check if already rated
    $stmt = $db->prepare("SELECT id FROM ratings WHERE appointment_id = ?");
    $stmt->execute([$appointmentId]);
    if ($stmt->fetch()) {
        jsonResponse(false, ['message' => 'You have already rated this appointment.']);
    }

    // Get appointment details with doctor info
    $stmt = $db->prepare(
        "SELECT a.doctor_id, u.name AS doctor_name FROM appointments a 
         LEFT JOIN users u ON a.doctor_id = u.id 
         WHERE a.id = ? AND a.user_id = ?"
    );
    $stmt->execute([$appointmentId, $userId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    $doctorId = (int)$appt['doctor_id'];
    $doctorName = $appt['doctor_name'] ?? 'the doctor';

    // Insert rating
    $stmt = $db->prepare("INSERT INTO ratings (appointment_id, user_id, doctor_id, stars, review, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$appointmentId, $userId, $doctorId, $stars, $review]);

    // Update doctor's average rating
    $stmt = $db->prepare("SELECT AVG(stars) as avg_rating FROM ratings WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    $avg = $stmt->fetch()['avg_rating'];
    $stmt = $db->prepare("UPDATE doctors SET rating = ? WHERE user_id = ?");
    $stmt->execute([round($avg, 1), $doctorId]);

    // Notify doctor
    $ns = new NotificationService($db);
    $patientName = $user['name'] ?? 'a patient';

    $ns->create(
        $doctorId,
        NotificationService::TYPE_RATING_RECEIVED,
        'New Rating Received',
        "You received a {$stars}-star rating from {$patientName}." . ($review ? " Review: {$review}" : ""),
        'rating',
        $appointmentId
    );

    // Notify admin
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    if ($admin) {
        $ns->create(
            (int)$admin['id'],
            NotificationService::TYPE_RATING_RECEIVED,
            'New Doctor Rating',
            "{$patientName} rated {$doctorName} {$stars} stars.",
            'rating',
            $appointmentId
        );
    }

    jsonResponse(true, ['message' => 'Rating submitted successfully.']);

} catch (Exception $e) {
    error_log('Submit Rating Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to submit rating.']);
}
