<?php
/**
 * HealthBridge — Book Appointment
 * Creates a new appointment with schedule validation and notifies relevant parties.
 *
 * Features:
 * - Validates against doctor's schedule (working days, hours, break, availability)
 * - Prevents double booking (race-condition safe)
 * - Notifies doctor (and patient if booked by admin)
 * - Supports patient self-booking and admin-assisted booking
 * - Backward-compatible: accepts legacy `department` (name) or new `department_id`
 *
 * The schedule validation is done BEFORE inserting the appointment,
 * ensuring only valid slots can be booked regardless of frontend state.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// ── Extract input fields ──
$departmentName = trim($input['department'] ?? '');
$departmentId   = (int)($input['department_id'] ?? 0);
$doctorName     = trim($input['doctor'] ?? '');
$doctorId       = (int)($input['doctor_id'] ?? 0);
$date           = trim($input['date'] ?? '');
$time           = trim($input['time'] ?? '');
$patientName    = trim($input['patientName'] ?? '');
$notes          = trim($input['notes'] ?? '');
$patientId      = (int)($input['patient_id'] ?? 0);

// ── Validate required fields (doctor, date, time, patientName are always required) ──
if (!$doctorName || !$date || !$time || !$patientName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
    exit;
}

// ── Department Resolution Logic ──
// At least one of department (name) or department_id must be provided.
if (!$departmentName && !$departmentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Department information is required.']);
    exit;
}

try {
    $db = getDB();

    // Resolve department: determine the authoritative department_id and department name
    $resolvedDeptId = 0;
    $resolvedDeptName = '';

    if ($departmentId > 0 && $departmentName !== '') {
        // Both provided: validate they refer to the same department
        $deptStmt = $db->prepare("SELECT id, name, status FROM departments WHERE id = ? AND name = ? LIMIT 1");
        $deptStmt->execute([$departmentId, $departmentName]);
        $deptRow = $deptStmt->fetch();

        if (!$deptRow) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The provided department ID and department name do not match.']);
            exit;
        }

        if ($deptRow['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This department is not currently accepting bookings.']);
            exit;
        }

        $resolvedDeptId = (int)$deptRow['id'];
        $resolvedDeptName = $deptRow['name'];

    } elseif ($departmentId > 0) {
        // Only department_id provided (admin.js, patient-emr.js flow)
        $deptStmt = $db->prepare("SELECT id, name, status FROM departments WHERE id = ? LIMIT 1");
        $deptStmt->execute([$departmentId]);
        $deptRow = $deptStmt->fetch();

        if (!$deptRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Department not found.']);
            exit;
        }

        if ($deptRow['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This department is not currently accepting bookings.']);
            exit;
        }

        $resolvedDeptId = (int)$deptRow['id'];
        $resolvedDeptName = $deptRow['name'];

    } else {
        // Only department name provided (dashboard.js patient self-booking flow)
        $deptStmt = $db->prepare("SELECT id, name, status FROM departments WHERE name = ? LIMIT 1");
        $deptStmt->execute([$departmentName]);
        $deptRow = $deptStmt->fetch();

        if (!$deptRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Department not found.']);
            exit;
        }

        if ($deptRow['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This department is not currently accepting bookings.']);
            exit;
        }

        $resolvedDeptId = (int)$deptRow['id'];
        $resolvedDeptName = $deptRow['name'];
    }

    // If doctor_id not provided, look up by name
    if (!$doctorId) {
        $stmt = $db->prepare("SELECT id FROM users WHERE name = ? AND role = 'doctor' LIMIT 1");
        $stmt->execute([$doctorName]);
        $doctorRow = $stmt->fetch();
        if (!$doctorRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Doctor not found.']);
            exit;
        }
        $doctorId = (int)$doctorRow['id'];
    }

    // ── Schedule Validation (Phase 2) ──
    // Normalize time to 24-hour format for schedule validation
    $normalizedTime = normalizeBookingTime($time);

    $ss = new ScheduleService($db);
    $validationError = $ss->validateBookingSlot($doctorId, $date, $normalizedTime);
    
    // ── Hospital Hours Enforcement (Defense-in-Depth) ──
    // Independently validate that the appointment fits within current hospital
    // operating hours. This is a hard global boundary that cannot be bypassed
    // even if the doctor's schedule is stale.
    $hospitalHours = $ss->getHospitalHours();
    $hospitalOpen = $hospitalHours['open'];
    $hospitalClose = $hospitalHours['close'];
    
    if ($normalizedTime < $hospitalOpen) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Selected time is before hospital opening (' . $hospitalOpen . ').']);
        exit;
    }
    
    // Ensure the entire appointment fits within hospital closing time
    $bookingDuration = getAppointmentDuration((int)$doctorId);
    $timeMinutes = (function($t) {
        $parts = explode(':', $t);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    })($normalizedTime);
    $closeMinutes = (function($t) {
        $parts = explode(':', $t);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    })($hospitalClose);
    
    if ($timeMinutes + $bookingDuration > $closeMinutes) {
        $latestStart = sprintf('%02d:%02d', (int)(($closeMinutes - $bookingDuration) / 60), ($closeMinutes - $bookingDuration) % 60);
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This appointment would end after hospital closing (' . $hospitalClose . '). Latest start time is ' . $latestStart . '.']);
        exit;
    }
    // ── End Hospital Hours Enforcement ──

    if ($validationError !== null) {
        http_response_code(409); // Conflict — slot unavailable
        echo json_encode(['success' => false, 'message' => $validationError]);
        exit;
    }

    // ── Double-Booking Prevention (Race Condition Guard) ──
    // Check again inside a transaction to prevent concurrent bookings at the same time
    $db->beginTransaction();

    try {
        // Lock the appointments table for this doctor/date/time to prevent race conditions
        $checkStmt = $db->prepare(
            "SELECT id FROM appointments
             WHERE doctor_id = ? AND date = ? AND time = ? AND status IN ('Pending', 'Confirmed')
             FOR UPDATE"
        );
        $checkStmt->execute([$doctorId, $date, $time]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This time slot was just booked by someone else. Please choose another.']);
            exit;
        }

        // ── Admin Booking Logic ──
        $appointmentUserId = $userId;
        $actualPatientName = $patientName;

        if ($userRole === 'admin') {
            if ($patientId > 0) {
                $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
                $stmt->execute([$patientId]);
                $patientRow = $stmt->fetch();
                if ($patientRow) {
                    $appointmentUserId = (int)$patientRow['id'];
                    $actualPatientName = $patientRow['name'];
                }
            } else {
                $stmt = $db->prepare("SELECT id, name FROM users WHERE name = ? AND role = 'patient' LIMIT 1");
                $stmt->execute([$patientName]);
                $patientRow = $stmt->fetch();
                if ($patientRow) {
                    $appointmentUserId = (int)$patientRow['id'];
                    $actualPatientName = $patientRow['name'];
                }
            }
        }

        // Generate and store the appointment_time_range at booking time
        // so it stays fixed even if the doctor changes their duration later
        $bookingDuration = getAppointmentDuration((int)$doctorId);
        $storedTimeRange = computeAppointmentTimeRange($time, $bookingDuration);

        // Insert appointment
        $stmt = $db->prepare(
            'INSERT INTO appointments (doctor_id, user_id, patient_name, department, department_id, doctor, date, time, appointment_time_range, notes, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending", NOW())'
        );
        $stmt->execute([$doctorId, $appointmentUserId, $actualPatientName, $resolvedDeptName, $resolvedDeptId, $doctorName, $date, $time, $storedTimeRange, $notes]);
        $appointmentId = (int)$db->lastInsertId();

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    // ── Notifications ──
    $ns = new NotificationService($db);
    
    // Generate time range for notification messages
    $duration = getAppointmentDuration((int)$doctorId);
    $timeRangeStr = computeAppointmentTimeRange($time, $duration);

    // Always notify the doctor
    $notifMessage = "{$actualPatientName} booked an appointment on {$date} ({$timeRangeStr}) for {$resolvedDeptName}.";
    $ns->create(
        $doctorId,
        NotificationService::TYPE_APPOINTMENT_REQUEST,
        'New Booking Request',
        $notifMessage,
        'appointment',
        $appointmentId
    );

    // If an admin booked for a patient, also notify the patient
    if ($userRole === 'admin' && $appointmentUserId !== $userId) {
        $patientNotifMessage = "An appointment has been booked for you with Dr. {$doctorName} on {$date} ({$timeRangeStr}) for {$resolvedDeptName}.";
        $ns->create(
            $appointmentUserId,
            NotificationService::TYPE_APPOINTMENT_REQUEST,
            'Appointment Booked for You',
            $patientNotifMessage,
            'appointment',
            $appointmentId
        );
    }

    // Log to audit
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('book', 'appointment', $appointmentId, null, 
        ['doctor_id' => $doctorId, 'patient_id' => $appointmentUserId, 'date' => $date, 'time' => $time],
        "Appointment booked: $actualPatientName with $doctorName on $date at $time", $appointmentUserId, $doctorId);

    echo json_encode([
        'success' => true,
        'message' => 'Appointment booked successfully!',
        'appointment_id' => $appointmentId,
    ]);

} catch (Exception $e) {
    error_log('Book Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to book appointment.']);
}

/**
 * Normalize a time string to 24-hour HH:MM format for schedule validation.
 * Handles both "09:00 AM" (12-hour) and "09:00" (24-hour) formats.
 *
 * @param string $time
 * @return string
 */
function normalizeBookingTime(string $time): string
{
    // If already in 24-hour format
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
        return $time;
    }

    // Try 12-hour format (e.g., "09:00 AM", "2:00 PM")
    $parsed = date_parse_from_format('h:i A', strtoupper($time));
    if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0) {
        return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
    }

    // Try alternative format "h:iA" (no space)
    $parsed = date_parse_from_format('h:iA', strtoupper($time));
    if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0) {
        return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
    }

    // Return as-is if we can't parse it
    return $time;
}
