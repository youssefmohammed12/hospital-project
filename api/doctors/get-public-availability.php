<?php
/**
 * HealthBridge — Get Public Doctor Availability (Phase 3)
 *
 * Returns availability information for public display on the doctors page.
 * Does NOT expose private schedule settings like break times or max appointments.
 *
 * GET: Returns batch availability for all doctors (single request, no per-doctor polling)
 * GET ?doctor_id=X: Returns availability for a single doctor
 *
 * Response structure:
 *   availability[doctor_id] = {
 *     available, accepting_patients, next_available, working_days, today_available
 *   }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

try {
    $db = getDB();
    /** @var ScheduleService $ss */
    $ss = new ScheduleService($db);

    $doctorId = (int)($_GET['doctor_id'] ?? 0);

    if ($doctorId > 0) {
        // Single doctor
        $avail = $ss->getPublicAvailability($doctorId);
        jsonResponse(true, [
            'availability' => [$doctorId => $avail],
        ]);
    } else {
        // Batch: get all doctors
        $stmt = $db->query(
            "SELECT d.user_id FROM doctors d
             JOIN users u ON d.user_id = u.id
             WHERE u.role = 'doctor' AND u.is_active = 1
             ORDER BY d.name ASC"
        );
        $doctorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $doctorIds = array_map('intval', $doctorIds);

        $availability = $ss->getBatchPublicAvailability($doctorIds);

        jsonResponse(true, [
            'availability' => $availability,
        ]);
    }

} catch (Exception $e) {
    error_log('Get Public Doctor Availability Error: ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Failed to load availability.', 'availability' => []]);
}

