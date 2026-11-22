<?php
/**
 * HealthBridge — Book Appointment
 *
 * HTTP boundary accepts modern IDs and older name-only client payloads.
 * All authoritative department/doctor/patient checks, locking, schedule and
 * overlap validation, inserts, audit, and outbox writes live in the
 * schema-aware AppointmentBookingRepository.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentBookingRepository.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAuth();
$actorUserId = (int)$user['id'];
$actorRole = (string)($user['role'] ?? '');

if (!in_array($actorRole, ['patient', 'admin'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only patients and administrators can book appointments.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid booking request payload.']);
}

$departmentName = is_string($input['department'] ?? null) ? trim($input['department']) : '';
$doctorName = is_string($input['doctor'] ?? null) ? trim($input['doctor']) : '';
$patientName = is_string($input['patientName'] ?? null) ? trim($input['patientName']) : '';
$date = is_string($input['date'] ?? null) ? trim($input['date']) : '';
$time = is_string($input['time'] ?? null) ? trim($input['time']) : '';
$notesValue = $input['notes'] ?? '';
if (!is_string($input['notes'] ?? '') && ($input['notes'] ?? null) !== null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment notes must be text.']);
}
$notes = is_string($notesValue) ? trim($notesValue) : '';

$departmentId = 0;
if (($input['department_id'] ?? null) !== null && ($input['department_id'] ?? '') !== '') {
    $departmentId = parsePositiveInt($input['department_id']);
    if ($departmentId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Department ID must be a positive integer.']);
    }
}
$doctorId = 0;
if (($input['doctor_id'] ?? null) !== null && ($input['doctor_id'] ?? '') !== '') {
    $doctorId = parsePositiveInt($input['doctor_id']);
    if ($doctorId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Doctor ID must be a positive integer.']);
    }
}
$requestedPatientId = 0;
if (($input['patient_id'] ?? null) !== null && ($input['patient_id'] ?? '') !== '' && ($input['patient_id'] ?? '') !== '0') {
    $requestedPatientId = parsePositiveInt($input['patient_id']);
    if ($requestedPatientId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Patient ID must be a positive integer.']);
    }
}

if ((!$doctorId && $doctorName === '') || $date === '' || $time === '' || $patientName === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'All required fields must be filled.']);
}
if (!$departmentId && $departmentName === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department information is required.']);
}
if (mb_strlen($notes) > 10000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment notes are too long.']);
}

try {
    $db = getDB();
    // The current HTTP runtime is V1. V2 booking has already been exercised
    // through the same repository's disposable-fixture contract.
    $schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_APPOINTMENTS_SCHEMA');

    // Resolve a legacy department-name payload into the canonical department ID.
    if ($departmentId > 0) {
        $departmentStmt = $db->prepare('SELECT id,name,status FROM departments WHERE id=? LIMIT 1');
        $departmentStmt->execute([$departmentId]);
        $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$department) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Department not found.']);
        }
        if ($departmentName !== '' && (string)$department['name'] !== $departmentName) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'The provided department ID and department name do not match.']);
        }
    } else {
        $departmentStmt = $db->prepare('SELECT id,name,status FROM departments WHERE name=? LIMIT 1');
        $departmentStmt->execute([$departmentName]);
        $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$department) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Department not found.']);
        }
        $departmentId = (int)$department['id'];
    }
    if ((string)$department['status'] !== 'active') {
        http_response_code(409);
        jsonResponse(false, ['message' => 'This department is not currently accepting bookings.']);
    }
    $departmentName = (string)$department['name'];

    // Legacy clients may send a doctor display name without an ID. Resolve only
    // an unambiguous active doctor from the selected department.
    if ($doctorId <= 0) {
        if ($schema === AppointmentBookingRepository::SCHEMA_V1) {
            $doctorSql = "SELECT u.id,u.name
                FROM users u JOIN doctors d ON d.user_id=u.id
                WHERE u.name=? AND u.role='doctor' AND u.is_active=1
                  AND (d.department_id=? OR (
                    d.department_id IS NULL
                    AND LOWER(TRIM(d.specialty))=LOWER(TRIM(?))
                  ))
                ORDER BY u.id LIMIT 2";
        } else {
            $doctorSql = "SELECT u.id,u.name
                FROM users u JOIN doctor_profiles d ON d.doctor_user_id=u.id
                WHERE u.name=? AND u.role='doctor' AND u.is_active=1
                  AND (d.department_id=? OR (
                    d.department_id IS NULL
                    AND LOWER(TRIM(d.specialty))=LOWER(TRIM(?))
                  ))
                ORDER BY u.id LIMIT 2";
        }
        $doctorStmt = $db->prepare($doctorSql);
        $doctorStmt->execute([$doctorName, $departmentId, $departmentName]);
        $matches = $doctorStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) > 1) {
            http_response_code(409);
            jsonResponse(false, ['message' => 'More than one doctor has that name in this department. Please select the doctor again.']);
        }
        if (!$matches) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'The selected doctor is not available in this department.']);
        }
        $doctorId = (int)$matches[0]['id'];
    }

    // Patient self-booking is always tied to the authenticated account.
    // Admin-assisted booking must resolve to a real active patient account.
    if ($actorRole === 'patient') {
        if ($requestedPatientId > 0 && $requestedPatientId !== $actorUserId) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'Patients can only book appointments for their own account.']);
        }
        $patientId = $actorUserId;
    } elseif ($requestedPatientId > 0) {
        $patientId = $requestedPatientId;
    } else {
        $patientStmt = $db->prepare(
            "SELECT id FROM users WHERE name=? AND role='patient' AND is_active=1 ORDER BY id LIMIT 2"
        );
        $patientStmt->execute([$patientName]);
        $matches = $patientStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches) > 1) {
            http_response_code(409);
            jsonResponse(false, ['message' => 'More than one patient has that name. Please select the patient again.']);
        }
        if (!$matches) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'The selected patient account was not found.']);
        }
        $patientId = (int)$matches[0];
    }

    $booking = new AppointmentBookingRepository($db, $schema);
    $appointmentId = $booking->book([
        'doctor_user_id' => $doctorId,
        'patient_user_id' => $patientId,
        'department_id' => $departmentId,
        'date' => $date,
        'time' => AppointmentTime::normalize($time),
        'notes' => $notes,
        'actor_user_id' => $actorUserId,
        'actor_role' => $actorRole,
    ]);

    // Booking and outbox writes have committed together. Dispatch is best-effort.
    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Book Appointment Outbox Dispatch Error: ' . get_class($notificationError));
    }

    jsonResponse(true, [
        'message' => 'Appointment booked successfully!',
        'appointment_id' => $appointmentId,
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'only book appointments for their own account')) {
        http_response_code(403);
    } elseif (str_contains($message, 'not found')) {
        http_response_code(404);
    } elseif (str_contains($message, 'not available') || str_contains($message, 'does not belong')
        || str_contains($message, 'overlap') || str_contains($message, 'slot')) {
        http_response_code(409);
    } else {
        http_response_code(400);
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
        jsonResponse(false, ['message' => 'The selected appointment time is no longer available.']);
    }
    error_log('Book Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to book appointment.']);
}
