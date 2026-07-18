<?php
/**
 * HealthBridge — Patient Registration API
 * 
 * Creates a new patient account with full profile information.
 * Supports 2-step registration: Step 1 (validation only) + Step 2 (atomic creation).
 * Uses database transactions to ensure ACID compliance — no partial accounts.
 * 
 * POST /api/auth/register.php
 * 
 * Step 1 (validation only — no DB insert):
 * Body: {
 *   "step": 1,
 *   "first_name": "...",
 *   "last_name": "...",
 *   "email": "...",
 *   "phone": "...",
 *   "password": "...",
 *   "confirm_password": "..."
 * }
 * 
 * Step 2 (atomic creation — single transaction):
 * Body: {
 *   "step": 2,
 *   "first_name": "...",
 *   "last_name": "...",
 *   "email": "...",
 *   "phone": "...",
 *   "password": "...",
 *   "national_id": "...",
 *   "date_of_birth": "YYYY-MM-DD",
 *   "gender": "...",
 *   "governorate": "...",
 *   "city": "...",
 *   "address": "...",
 *   "blood_type": "...",
 *   "emergency_contact_name": "...",
 *   "emergency_contact_phone": "...",
 *   "emergency_contact_rel": "...",
 *   "allergies": "...",
 *   "chronic_diseases": "...",
 *   "current_medications": "...",
 *   "insurance_provider": "...",
 *   "insurance_number": "..."
 * }
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$step = (int)($input['step'] ?? 0);

try {
    $db = getDB();
    
    if ($step === 1) {
        handleStep1($db, $input);
    } elseif ($step === 2) {
        handleStep2($db, $input);
    } else {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Invalid step. Must be 1 or 2.']);
    }
} catch (Exception $e) {
    error_log('Register Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Registration failed. Please try again.']);
}

/**
 * Handle Step 1: Account Information — VALIDATION ONLY
 * 
 * Validates all required fields, checks uniqueness constraints,
 * and returns success. Does NOT insert anything into the database.
 * The validated data is stored on the client and submitted with Step 2.
 */
function handleStep1(PDO $db, array $input): void {
    // Sanitize inputs
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    
    // --- Validation ---
    $errors = [];
    
    // First name
    $fnError = validateRequired($firstName, 'First name');
    if ($fnError) $errors[] = $fnError;
    
    // Last name
    $lnError = validateRequired($lastName, 'Last name');
    if ($lnError) $errors[] = $lnError;
    
    // Email
    $emailError = validateEmail($email);
    if ($emailError) {
        $errors[] = $emailError;
    } elseif (isEmailTaken($db, $email)) {
        $errors[] = 'An account with this email already exists.';
    }
    
    // Phone
    $phoneError = validatePhone($phone);
    if ($phoneError) {
        $errors[] = $phoneError;
    } elseif (isPhoneTaken($db, $phone)) {
        $errors[] = 'This phone number is already registered.';
    }
    
    // Password
    $pwValidation = validatePassword($password);
    if (!$pwValidation['valid']) {
        foreach ($pwValidation['errors'] as $err) {
            $errors[] = $err;
        }
    }
    
    // Confirm password
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors]);
    }
    
    // --- Validation passed — no DB insert ---
    jsonResponse(true, [
        'message' => 'Validation passed. Please complete your patient profile.',
        'next_step' => 2,
    ]);
}

/**
 * Handle Step 2: Complete Registration — ATOMIC INSERT
 * 
 * Receives ALL registration data (Step 1 + Step 2 fields) and
 * performs a single atomic transaction to create the complete account.
 * If any operation fails, the entire transaction is rolled back.
 */
function handleStep2(PDO $db, array $input): void {
    // --- Extract Step 1 fields ---
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    
    // --- Extract Step 2 fields ---
    $nationalId = trim($input['national_id'] ?? '');
    $dateOfBirth = trim($input['date_of_birth'] ?? '');
    $gender = trim($input['gender'] ?? '');
    $governorate = trim($input['governorate'] ?? '');
    $city = trim($input['city'] ?? '');
    $address = trim($input['address'] ?? '');
    $bloodType = trim($input['blood_type'] ?? '');
    $emergencyName = trim($input['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($input['emergency_contact_phone'] ?? '');
    $emergencyRel = trim($input['emergency_contact_rel'] ?? '');
    $allergies = trim($input['allergies'] ?? '');
    $chronicDiseases = trim($input['chronic_diseases'] ?? '');
    $currentMedications = trim($input['current_medications'] ?? '');
    $insuranceProvider = trim($input['insurance_provider'] ?? '');
    $insuranceNumber = trim($input['insurance_number'] ?? '');
    
    // --- Full Validation (all fields from both steps) ---
    $errors = [];
    
    // Step 1 validations
    $fnError = validateRequired($firstName, 'First name');
    if ($fnError) $errors[] = $fnError;
    
    $lnError = validateRequired($lastName, 'Last name');
    if ($lnError) $errors[] = $lnError;
    
    $emailError = validateEmail($email);
    if ($emailError) {
        $errors[] = $emailError;
    } elseif (isEmailTaken($db, $email)) {
        $errors[] = 'An account with this email already exists.';
    }
    
    $phoneError = validatePhone($phone);
    if ($phoneError) {
        $errors[] = $phoneError;
    } elseif (isPhoneTaken($db, $phone)) {
        $errors[] = 'This phone number is already registered.';
    }
    
    $pwValidation = validatePassword($password);
    if (!$pwValidation['valid']) {
        foreach ($pwValidation['errors'] as $err) {
            $errors[] = $err;
        }
    }
    
    // Step 2 validations
    $nidError = validateRequired($nationalId, 'National ID');
    if ($nidError) {
        $errors[] = $nidError;
    } else {
        $nidValidation = validateNationalId($nationalId, $dateOfBirth ?: null);
        if (!$nidValidation['valid']) {
            $errors[] = $nidValidation['error'];
        } elseif (isNationalIdTaken($db, $nationalId)) {
            $errors[] = 'This National ID is already registered.';
        }
    }
    
    $dobError = validateDate($dateOfBirth, 'Date of Birth');
    if ($dobError) $errors[] = $dobError;
    
    $genderError = validateRequired($gender, 'Gender');
    if ($genderError) $errors[] = $genderError;
    
    $govError = validateRequired($governorate, 'Governorate');
    if ($govError) $errors[] = $govError;
    
    $cityError = validateRequired($city, 'City');
    if ($cityError) $errors[] = $cityError;
    
    $addrError = validateRequired($address, 'Full address');
    if ($addrError) $errors[] = $addrError;
    
    $validBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    if ($bloodType !== '' && !in_array($bloodType, $validBloodTypes, true)) {
        $errors[] = 'Invalid blood type.';
    }
    
    $ecnError = validateRequired($emergencyName, 'Emergency contact name');
    if ($ecnError) $errors[] = $ecnError;
    
    $ecpError = validateRequired($emergencyPhone, 'Emergency contact phone');
    if ($ecpError) {
        $errors[] = $ecpError;
    } else {
        $phoneError = validatePhone($emergencyPhone);
        if ($phoneError) $errors[] = 'Emergency contact: ' . $phoneError;
    }
    
    $ecrError = validateRequired($emergencyRel, 'Emergency contact relationship');
    if ($ecrError) $errors[] = $ecrError;
    
    if (!empty($errors)) {
        http_response_code(400);
        jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors]);
    }
    
    // --- Atomic Transaction: Create everything or nothing ---
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $fullName = formatFullName($firstName, $lastName);
    $patientNumber = generatePatientNumber($db);
    
    $db->beginTransaction();
    try {
        // 1. Insert user with ALL fields (including national_id and patient_number)
        $stmt = $db->prepare("
            INSERT INTO users (name, first_name, last_name, email, password, phone, role, national_id, patient_number, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'patient', ?, ?, NOW())
        ");
        $stmt->execute([$fullName, $firstName, $lastName, $email, $hashedPassword, $phone, $nationalId, $patientNumber]);
        $userId = (int)$db->lastInsertId();
        
        // 2. Create default preferences
        $stmt = $db->prepare("INSERT IGNORE INTO user_preferences (user_id) VALUES (?)");
        $stmt->execute([$userId]);
        
        // 3. Create medical records with full patient data
        $stmt = $db->prepare("
            INSERT INTO medical_records (
                patient_id, date_of_birth, gender, blood_type, governorate, city, address,
                emergency_contact_name, emergency_contact_phone, emergency_contact_rel,
                allergies, chronic_diseases, current_medications,
                insurance_provider, insurance_number, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, NOW(), NOW()
            )
        ");
        $stmt->execute([
            $userId,
            $dateOfBirth ?: null,
            $gender,
            $bloodType ?: null,
            $governorate,
            $city,
            $address,
            $emergencyName,
            $emergencyPhone,
            $emergencyRel,
            $allergies ?: null,
            $chronicDiseases ?: null,
            $currentMedications ?: null,
            $insuranceProvider ?: null,
            $insuranceNumber ?: null,
        ]);
        
        // 4. Log audit entry
        require_once __DIR__ . '/../../services/AuditService.php';
        $audit = new AuditService($db, $userId, 'patient');
        $audit->log(
            'create',
            'patient',
            $userId,
            null,
            [
                'first_name' => $firstName,
                'patient_number' => $patientNumber,
                'national_id' => '[REDACTED]',
            ],
            'New patient account fully registered with patient number: ' . $patientNumber,
            $userId,
            null
        );
        
        // 5. Notify all admins
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role = 'admin'");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll();
        
        if (!empty($admins)) {
            require_once __DIR__ . '/../../services/NotificationService.php';
            $ns = new NotificationService($db);
            
            foreach ($admins as $admin) {
                $ns->create(
                    (int)$admin['id'],
                    NotificationService::TYPE_NEW_PATIENT,
                    'New Patient Registered',
                    "{$fullName} ({$patientNumber}) has completed registration.",
                    'user',
                    $userId
                );
            }
        }
        
        // 6. Commit — all operations succeeded
        $db->commit();
        
        jsonResponse(true, [
            'message' => 'Registration complete! Welcome to HealthBridge.',
            'patient_number' => $patientNumber,
            'user_id' => $userId,
        ]);
    } catch (Exception $e) {
        // Roll back the entire transaction on any failure
        $db->rollBack();
        throw $e;
    }
}
