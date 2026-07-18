<?php
/**
 * HealthBridge — Input Validation & Sanitization Helpers
 * 
 * Provides reusable validation functions for authentication,
 * patient registration, and general input sanitization.
 */

/**
 * Sanitize a string input
 * @param string|null $input
 * @param int $maxLength
 * @return string
 */
function sanitizeString($input, int $maxLength = 255): string {
    if ($input === null) return '';
    $clean = trim((string)$input);
    $clean = htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
    return mb_substr($clean, 0, $maxLength);
}

/**
 * Validate email format
 * @param string $email
 * @return string|null Error message or null if valid
 */
function validateEmail(string $email): ?string {
    $email = trim($email);
    if ($email === '') {
        return 'Email address is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (strlen($email) > 150) {
        return 'Email address must not exceed 150 characters.';
    }
    return null;
}

/**
 * Validate Egyptian phone number
 * Accepts: +2010XXXXXXXX, 010XXXXXXXX, 011XXXXXXXX, 012XXXXXXXX, 015XXXXXXXX
 * @param string $phone
 * @return string|null Error message or null if valid
 */
function validatePhone(string $phone): ?string {
    $phone = trim($phone);
    if ($phone === '') {
        return 'Phone number is required.';
    }
    // Remove spaces, dashes, parentheses
    $clean = preg_replace('/[\s\-\(\)]+/', '', $phone);
    // Egyptian mobile: +2010/11/12/15 followed by 8 digits
    if (preg_match('/^(\+20|0)(10|11|12|15)\d{8}$/', $clean)) {
        return null;
    }
    // Egyptian landline: +20XX or 0XX followed by 7-8 digits
    if (preg_match('/^(\+20|0)(2|3|4|5|6|7|8|9)\d{7,8}$/', $clean)) {
        return null;
    }
    return 'Please enter a valid Egyptian phone number (e.g., 010XXXXXXXX).';
}

/**
 * Validate password strength
 * @param string $password
 * @return array{valid: bool, errors: string[], strength: string, checks: array}
 */
function validatePassword(string $password): array {
    $checks = [
        'min_length' => strlen($password) >= 8,
        'has_uppercase' => (bool)preg_match('/[A-Z]/', $password),
        'has_lowercase' => (bool)preg_match('/[a-z]/', $password),
        'has_number' => (bool)preg_match('/[0-9]/', $password),
        'has_special' => (bool)preg_match('/[^A-Za-z0-9]/', $password),
    ];
    
    $errors = [];
    if (!$checks['min_length']) $errors[] = 'Password must be at least 8 characters.';
    if (!$checks['has_uppercase']) $errors[] = 'Password must include an uppercase letter.';
    if (!$checks['has_lowercase']) $errors[] = 'Password must include a lowercase letter.';
    if (!$checks['has_number']) $errors[] = 'Password must include a number.';
    if (!$checks['has_special']) $errors[] = 'Password must include a special character.';
    
    // Calculate strength
    $score = 0;
    foreach ($checks as $pass) {
        if ($pass) $score++;
    }
    
    $strength = 'weak';
    if ($score >= 5) {
        $strength = 'very_strong';
    } elseif ($score >= 4) {
        $strength = 'strong';
    } elseif ($score >= 3) {
        $strength = 'medium';
    }
    
    return [
        'valid' => $score >= 4, // Require at least 4 out of 5 checks
        'errors' => $errors,
        'strength' => $strength,
        'checks' => $checks,
    ];
}

/**
 * Validate Egyptian National ID (14 digits)
 * Also extracts birth date for additional validation
 * @param string $id
 * @param string|null $dateOfBirth YYYY-MM-DD to cross-verify
 * @return array{valid: bool, error: string|null, birth_date: string|null, century: int|null}
 */
function validateNationalId(string $id, ?string $dateOfBirth = null): array {
    $id = trim($id);
    $result = [
        'valid' => false,
        'error' => null,
        'birth_date' => null,
        'century' => null,
    ];
    
    if ($id === '') {
        $result['error'] = 'National ID is required.';
        return $result;
    }
    
    // Must be exactly 14 digits
    if (!preg_match('/^\d{14}$/', $id)) {
        $result['error'] = 'National ID must be exactly 14 digits.';
        return $result;
    }
    
    // Egyptian National ID format:
    // CC YY MM DD SS GGG XXX
    // CC = Century (2 = 1900-1999, 3 = 2000-2099)
    // YY = Year (within century)
    // MM = Month
    // DD = Day
    // SS = Governorate code
    // G = Gender (0-4 = Male, 5-9 = Female)
    // XXX = Serial number
    
    $centuryDigit = (int)$id[0];
    $yearDigits = (int)substr($id, 1, 2);
    $monthDigits = (int)substr($id, 3, 2);
    $dayDigits = (int)substr($id, 5, 2);
    $genderDigit = (int)$id[11];
    
    // Validate century
    if ($centuryDigit === 2) {
        $century = 1900;
    } elseif ($centuryDigit === 3) {
        $century = 2000;
    } else {
        $result['error'] = 'Invalid National ID: unrecognized century code.';
        return $result;
    }
    
    $birthYear = $century + $yearDigits;
    
    // Validate month
    if ($monthDigits < 1 || $monthDigits > 12) {
        $result['error'] = 'Invalid National ID: invalid month.';
        return $result;
    }
    
    // Validate day
    $maxDays = cal_days_in_month(CAL_GREGORIAN, $monthDigits, $birthYear);
    if ($dayDigits < 1 || $dayDigits > $maxDays) {
        $result['error'] = 'Invalid National ID: invalid day for the given month.';
        return $result;
    }
    
    $extractedBirthDate = sprintf('%04d-%02d-%02d', $birthYear, $monthDigits, $dayDigits);
    $result['birth_date'] = $extractedBirthDate;
    $result['century'] = $century;
    
    // Validate gender digit (must be 0-9)
    if ($genderDigit < 0 || $genderDigit > 9) {
        $result['error'] = 'Invalid National ID: invalid gender code.';
        return $result;
    }
    
    // Cross-verify with provided date of birth if given
    if ($dateOfBirth !== null && $dateOfBirth !== '') {
        if ($extractedBirthDate !== $dateOfBirth) {
            $result['error'] = 'National ID birth date does not match the selected Date of Birth.';
            return $result;
        }
    }
    
    // All validations passed
    $result['valid'] = true;
    $result['gender'] = $genderDigit >= 5 ? 'Female' : 'Male';
    
    return $result;
}

/**
 * Validate that a required field is not empty
 * @param string $value
 * @param string $fieldName
 * @return string|null Error message or null if valid
 */
function validateRequired(string $value, string $fieldName): ?string {
    if (trim($value) === '') {
        return "{$fieldName} is required.";
    }
    return null;
}

/**
 * Check if email already exists in users table
 * @param PDO $db
 * @param string $email
 * @param int|null $excludeId User ID to exclude (for updates)
 * @return bool
 */
function isEmailTaken(PDO $db, string $email, ?int $excludeId = null): bool {
    if ($excludeId) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $excludeId]);
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
    }
    return (bool)$stmt->fetch();
}

/**
 * Check if phone number already exists in users table
 * @param PDO $db
 * @param string $phone
 * @param int|null $excludeId User ID to exclude (for updates)
 * @return bool
 */
function isPhoneTaken(PDO $db, string $phone, ?int $excludeId = null): bool {
    // Normalize phone for comparison
    $clean = preg_replace('/[\s\-\(\)]+/', '', $phone);
    if ($excludeId) {
        $stmt = $db->prepare("SELECT id FROM users WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') = ? AND id != ?");
        $stmt->execute([$clean, $excludeId]);
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') = ?");
        $stmt->execute([$clean]);
    }
    return (bool)$stmt->fetch();
}

/**
 * Check if national ID already exists in users table
 * @param PDO $db
 * @param string $nationalId
 * @param int|null $excludeId User ID to exclude (for updates)
 * @return bool
 */
function isNationalIdTaken(PDO $db, string $nationalId, ?int $excludeId = null): bool {
    if ($excludeId) {
        $stmt = $db->prepare("SELECT id FROM users WHERE national_id = ? AND id != ?");
        $stmt->execute([$nationalId, $excludeId]);
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE national_id = ?");
        $stmt->execute([$nationalId]);
    }
    return (bool)$stmt->fetch();
}

/**
 * Check if patient number already exists
 * @param PDO $db
 * @param string $patientNumber
 * @return bool
 */
function isPatientNumberTaken(PDO $db, string $patientNumber): bool {
    $stmt = $db->prepare("SELECT id FROM users WHERE patient_number = ?");
    $stmt->execute([$patientNumber]);
    return (bool)$stmt->fetch();
}

/**
 * Generate a unique patient number
 * Format: HB-YYYY-NNNNNN (e.g., HB-2026-000154)
 * @param PDO $db
 * @return string
 */
function generatePatientNumber(PDO $db): string {
    $year = date('Y');
    $prefix = "HB-{$year}-";
    
    // Find the max sequence number for the current year
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING(patient_number, LENGTH(?) + 1) AS UNSIGNED)) 
        FROM users 
        WHERE patient_number LIKE ?
    ");
    $likePattern = "HB-{$year}-%";
    $stmt->execute([$prefix, $likePattern]);
    $maxSeq = (int)$stmt->fetchColumn();
    
    $newSeq = $maxSeq + 1;
    $patientNumber = $prefix . str_pad((string)$newSeq, 6, '0', STR_PAD_LEFT);
    
    // Safety: ensure uniqueness (handle race condition edge case)
    $retries = 0;
    while (isPatientNumberTaken($db, $patientNumber) && $retries < 10) {
        $newSeq++;
        $patientNumber = $prefix . str_pad((string)$newSeq, 6, '0', STR_PAD_LEFT);
        $retries++;
    }
    
    return $patientNumber;
}

/**
 * Generate full display name from first_name and last_name
 * @param string $firstName
 * @param string $lastName
 * @return string
 */
function formatFullName(string $firstName, string $lastName): string {
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    if ($firstName === '' && $lastName === '') return '';
    if ($firstName === '') return $lastName;
    if ($lastName === '') return $firstName;
    return "{$firstName} {$lastName}";
}

/**
 * Get list of Egyptian governorates from database
 * @param PDO $db
 * @return array
 */
function getGovernorates(PDO $db): array {
    $stmt = $db->query("SELECT id, name FROM egypt_governorates ORDER BY sort_order, name ASC");
    return $stmt->fetchAll();
}

/**
 * Get cities for a specific governorate
 * @param PDO $db
 * @param int $governorateId
 * @return array
 */
function getCitiesByGovernorate(PDO $db, int $governorateId): array {
    $stmt = $db->prepare("SELECT id, name FROM egypt_cities WHERE governorate_id = ? ORDER BY name ASC");
    $stmt->execute([$governorateId]);
    return $stmt->fetchAll();
}

/**
 * Validate date format and value
 * @param string $date YYYY-MM-DD
 * @param string $fieldName
 * @return string|null Error message or null if valid
 */
function validateDate(string $date, string $fieldName = 'Date'): ?string {
    if (trim($date) === '') {
        return "{$fieldName} is required.";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return "{$fieldName} must be in YYYY-MM-DD format.";
    }
    $parts = explode('-', $date);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        return "{$fieldName} is not a valid date.";
    }
    return null;
}

/**
 * Sanitize an array of inputs using sanitizeString
 * @param array $inputs Associative array of field => value
 * @param int $maxLength
 * @return array
 */
function sanitizeArray(array $inputs, int $maxLength = 255): array {
    $result = [];
    foreach ($inputs as $key => $value) {
        if (is_string($value)) {
            $result[$key] = sanitizeString($value, $maxLength);
        } elseif (is_array($value)) {
            $result[$key] = sanitizeArray($value, $maxLength);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}