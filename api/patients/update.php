<?php
/**
 * HealthBridge — Admin: Update Patient
 * Updates patient identity/contact/demographics, account status, or password.
 * All reads, uniqueness checks, writes, audit and outbox entries are delegated
 * to PatientManagementRepository so v1/v2 share one lifecycle contract.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$patientIdValue = $input['patient_id'] ?? null;
$patientId = (is_int($patientIdValue) || (is_string($patientIdValue) && ctype_digit($patientIdValue)))
    ? filter_var((string)$patientIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($patientId === false || $patientId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();
    $management = new PatientManagementRepository($db);

    // Password-reset modal uses a dedicated path and never clears profile fields.
    $passwordOnlyRequest = array_key_exists('password', $input)
        && !array_key_exists('name', $input)
        && !array_key_exists('email', $input)
        && !array_key_exists('phone', $input)
        && !array_key_exists('gender', $input)
        && !array_key_exists('date_of_birth', $input)
        && !array_key_exists('is_active', $input);

    if ($passwordOnlyRequest) {
        $newPassword = $input['password'];
        $confirmPassword = $input['confirm_password'] ?? null;
        if (!is_string($newPassword) || !is_string($confirmPassword)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Password and confirmation must be text.']);
        }
        $passwordValidation = validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            http_response_code(400);
            jsonResponse(false, ['message' => implode(' ', $passwordValidation['errors'])]);
        }
        if ($newPassword !== $confirmPassword) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Passwords do not match.']);
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $management->resetPatientPassword((int)$patientId, $passwordHash, (int)$admin['id']);
        $notifications = new NotificationService($db);
        try {
            $notifications->processOutbox(50);
        } catch (Throwable $notificationError) {
            error_log('Patient Password Reset Outbox Dispatch Error: ' . get_class($notificationError));
        }
        jsonResponse(true, [
            'message' => 'Patient password reset successfully. Their previous sessions have been revoked; they must log in again.',
        ]);
    }

    $nameValue = $input['name'] ?? null;
    $emailValue = $input['email'] ?? null;
    $phoneValue = $input['phone'] ?? null;
    foreach (['name' => $nameValue, 'email' => $emailValue, 'phone' => $phoneValue] as $field => $value) {
        if (!is_string($value)) {
            http_response_code(400);
            jsonResponse(false, [ 'message' => ucfirst($field) . ' must be text.' ]);
        }
    }

    $name = trim($nameValue);
    $email = trim($emailValue);
    $phone = trim($phoneValue);

    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Patient name is required and must not exceed 100 characters.']);
    }
    $emailError = validateEmail($email);
    if ($emailError !== null) {
        http_response_code(400);
        jsonResponse(false, ['message' => $emailError]);
    }
    if ($phone !== '') {
        $phoneError = validatePhone($phone);
        if ($phoneError !== null) {
            http_response_code(400);
            jsonResponse(false, ['message' => $phoneError]);
        }
    }

    $changes = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
    ];

    if (array_key_exists('gender', $input)) {
        if (!is_string($input['gender'])) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Gender must be text.']);
        }
        $gender = trim($input['gender']);
        if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Gender must be Male, Female, or Other.']);
        }
        if ($gender !== '') {
            $changes['gender'] = $gender;
        }
    }

    if (array_key_exists('date_of_birth', $input)) {
        if (!is_string($input['date_of_birth'])) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Date of birth must be text.']);
        }
        $dob = trim($input['date_of_birth']);
        if ($dob !== '' && validateDate($dob, 'Date of birth') !== null) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Date of birth must be a valid YYYY-MM-DD date.']);
        }
        if ($dob !== '') {
            $changes['date_of_birth'] = $dob;
        }
    }

    if (array_key_exists('is_active', $input) && $input['is_active'] !== null) {
        $status = $input['is_active'];
        if (is_bool($status)) {
            $changes['is_active'] = $status ? 1 : 0;
        } elseif (
            (is_int($status) && ($status === 0 || $status === 1))
            || (is_string($status) && ($status === '0' || $status === '1'))
        ) {
            $changes['is_active'] = (int)$status;
        } else {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Account status must be a boolean or 0/1 value.']);
        }
    }

    $passwordChanged = false;
    $password = $input['password'] ?? '';
    if (!is_string($password)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Password must be text.']);
    }
    if ($password !== '') {
        $passwordValidation = validatePassword($password);
        if (!$passwordValidation['valid']) {
            http_response_code(400);
            jsonResponse(false, ['message' => implode(' ', $passwordValidation['errors'])]);
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        $changes['password_hash'] = $passwordHash;
        $passwordChanged = true;
    }

    $result = $management->updatePatient((int)$patientId, $changes, (int)$admin['id'], 'admin');
    if (!$result['changed']) {
        jsonResponse(true, ['message' => 'No changes detected.']);
    }

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Update Patient Outbox Dispatch Error: ' . get_class($notificationError));
    }

    $successMessage = $passwordChanged
        ? 'Patient updated successfully. Their previous sessions have been revoked; they must log in again.'
        : 'Patient updated successfully!';
    jsonResponse(true, ['message' => $successMessage]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if ($message === 'Patient not found.') {
        http_response_code(404);
    } elseif (str_contains($message, 'already in use')) {
        http_response_code(409);
    } elseif (str_contains($message, 'active')) {
        http_response_code(403);
    } else {
        http_response_code(409);
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
        jsonResponse(false, ['message' => 'The submitted email or phone number is already in use.']);
    }
    error_log('Update Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update patient.']);
}
