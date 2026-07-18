<?php
/**
 * HealthBridge — Admin: Delete Doctor
 * Removes a doctor account, their profile, cancels active appointments, and notifies patients.
 * POST { id }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

requireRole('admin');

$data = getJsonInput();
$id   = (int)($data['id'] ?? 0);

if (!$id) {
    jsonResponse(false, ['message' => 'Invalid doctor ID.']);
}

try {
    $db = getDB();

    // Verify doctor exists (fetch email for audit)
    $check = $db->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $check->execute([$id]);
    $doctor = $check->fetch();

    if (!$doctor) {
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }

    $db->beginTransaction();

    // Delete doctor profile first (FK constraint)
    $db->prepare('DELETE FROM doctors WHERE user_id = ?')->execute([$id]);

    // Cancel active appointments and notify patients
    $apptStmt = $db->prepare(
        'SELECT id, user_id, date, time FROM appointments
         WHERE doctor_id = ? AND status IN ("Pending","Confirmed")'
    );
    $apptStmt->execute([$id]);

    foreach ($apptStmt->fetchAll() as $appt) {
        $db->prepare('UPDATE appointments SET status = "Cancelled" WHERE id = ?')->execute([$appt['id']]);

        if ($appt['user_id']) {
            try {
                $db->prepare(
                    'INSERT INTO notifications (user_id, type, title, message, ref_type, ref_id, created_at)
                     VALUES (?, "appointment", "Appointment Cancelled", ?, "appointment", ?, NOW())'
                )->execute([
                    $appt['user_id'],
                    "Your appointment on {$appt['date']} at {$appt['time']} was cancelled because the doctor was removed from HealthBridge.",
                    $appt['id']
                ]);
            } catch (Exception $ex) {
                error_log('Patient notification failed: ' . $ex->getMessage());
            }
        }
    }

    // Log deletion BEFORE the user row is hard-deleted so actor FK is still valid
    try {
        $audit = new AdminAuditService($db, (int)$_SESSION['user_id']);
        $audit->log(
            'delete',
            'doctor',
            $id,
            ['name' => $doctor['name'], 'email' => $doctor['email'] ?? 'N/A', 'id' => $id],
            null,
            "Doctor account deleted: {$doctor['name']}",
            null,
            $id
        );
    } catch (Exception $auditErr) {
        error_log('Audit log error (delete_doctor): ' . $auditErr->getMessage());
    }

    // Delete doctor user account
    $db->prepare('DELETE FROM users WHERE id = ? AND role = "doctor"')->execute([$id]);

    $db->commit();

    jsonResponse(true, ['message' => "Doctor '{$doctor['name']}' deleted successfully."]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Delete Doctor Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to delete doctor. Please try again.']);
}

