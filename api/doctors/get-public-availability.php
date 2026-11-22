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
require_once __DIR__ . '/../../services/Repositories/DoctorScheduleRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.', 'availability' => []]);
}

$doctorIdValue = $_GET['doctor_id'] ?? null;
if ($doctorIdValue !== null && (!is_string($doctorIdValue) || !ctype_digit($doctorIdValue) || (int)$doctorIdValue <= 0)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID must be a positive integer.', 'availability' => []]);
}

try {
    $db = getDB();
    $schema = ScheduleService::schemaFromEnvironment();
    /** @var ScheduleService $ss */
    $ss = new ScheduleService($db, $schema);

    $doctorId = $doctorIdValue !== null ? (int)$doctorIdValue : 0;

    if ($doctorId > 0) {
        // Single doctor: do not expose availability for an archived/inactive account.
        if ($schema === DoctorScheduleRepository::SCHEMA_V1) {
            $activeDoctor = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'doctor' AND is_active = 1 LIMIT 1");
        } else {
            $activeDoctor = $db->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                 LEFT JOIN departments dep ON dep.id=dp.department_id
                 WHERE u.id=? AND u.role='doctor' AND u.is_active=1
                   AND dp.profile_visible=1
                   AND (dp.department_id IS NULL OR dep.status='active' OR dep.id IS NULL)
                 LIMIT 1"
            );
        }
        $activeDoctor->execute([$doctorId]);
        if (!$activeDoctor->fetchColumn()) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Doctor not found.', 'availability' => []]);
        }
        $avail = $ss->getPublicAvailability($doctorId);
        jsonResponse(true, [
            'availability' => [$doctorId => $avail],
        ]);
    } else {
        // Batch: only public, active doctor profiles are eligible.
        if ($schema === DoctorScheduleRepository::SCHEMA_V1) {
            $stmt = $db->query(
                "SELECT d.user_id FROM doctors d
                 JOIN users u ON d.user_id=u.id
                 WHERE u.role='doctor' AND u.is_active=1
                 ORDER BY d.name ASC"
            );
        } else {
            $stmt = $db->query(
                "SELECT dp.doctor_user_id
                 FROM doctor_profiles dp
                 JOIN users u ON u.id=dp.doctor_user_id
                 LEFT JOIN departments dep ON dep.id=dp.department_id
                 WHERE u.role='doctor' AND u.is_active=1
                   AND dp.profile_visible=1
                   AND (dp.department_id IS NULL OR dep.status='active' OR dep.id IS NULL)
                 ORDER BY u.name ASC"
            );
        }
        $doctorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $doctorIds = array_map('intval', $doctorIds);

        $availability = $ss->getBatchPublicAvailability($doctorIds);

        jsonResponse(true, [
            'availability' => $availability,
        ]);
    }

} catch (Throwable $e) {
    error_log('Get Public Doctor Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load availability.', 'availability' => []]);
}

