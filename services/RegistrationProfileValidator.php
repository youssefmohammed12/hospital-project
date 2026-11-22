<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/validation.php';

/**
 * Shared patient-registration validation for the HTTP intake and CLI worker.
 * Uniqueness is deliberately excluded; database unique indexes are authoritative
 * when the asynchronous worker creates the account.
 */
final class RegistrationProfileValidator
{
    private const PROFILE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'national_id',
        'date_of_birth',
        'gender',
        'governorate',
        'city',
        'address',
        'blood_type',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_rel',
        'allergies',
        'chronic_diseases',
        'current_medications',
        'insurance_provider',
        'insurance_number',
    ];

    /**
     * Server-side limits reflect the current UI and master-schema column sizes.
     * TEXT fields are deliberately capped to keep encrypted queue payloads bounded.
     *
     * @var array<string,int>
     */
    private const MAX_LENGTHS = [
        'first_name' => 50,
        'last_name' => 50,
        'email' => 150,
        'phone' => 20,
        'national_id' => 14,
        'date_of_birth' => 10,
        'gender' => 20,
        'governorate' => 100,
        'city' => 100,
        'address' => 4000,
        'blood_type' => 5,
        'emergency_contact_name' => 100,
        'emergency_contact_phone' => 20,
        'emergency_contact_rel' => 50,
        'allergies' => 4000,
        'chronic_diseases' => 4000,
        'current_medications' => 4000,
        'insurance_provider' => 200,
        'insurance_number' => 100,
    ];

    /**
     * @return array{data: array<string,string>, errors: string[]}
     */
    public static function validateHttp(array $input): array
    {
        $data = self::extractProfile($input, $errors);
        if ($errors !== []) {
            return ['data' => $data, 'errors' => $errors];
        }

        $password = $input['password'] ?? null;
        $confirmation = $input['confirm_password'] ?? null;
        if (!is_string($password) || !is_string($confirmation)) {
            $errors[] = 'Password fields must be submitted as text.';
        } else {
            $passwordValidation = validatePassword($password);
            if (!$passwordValidation['valid']) {
                $errors = array_merge($errors, $passwordValidation['errors']);
            }
            if (!hash_equals($password, $confirmation)) {
                $errors[] = 'Passwords do not match.';
            }
        }

        self::validateProfileFields($data, $errors);

        return ['data' => $data, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Validate a decrypted queue payload without ever requiring plaintext
     * passwords to be stored in the queue.
     *
     * @return array{data: array<string,string>, errors: string[]}
     */
    public static function validateQueued(array $input): array
    {
        $data = self::extractProfile($input, $errors);
        $passwordHash = $input['password_hash'] ?? null;

        if (!is_string($passwordHash) || $passwordHash === '' || strlen($passwordHash) > 255) {
            $errors[] = 'Queued credential representation is invalid.';
        } else {
            $hashInfo = password_get_info($passwordHash);
            if (($hashInfo['algoName'] ?? 'unknown') === 'unknown') {
                $errors[] = 'Queued credential representation is invalid.';
            }
            $data['password_hash'] = $passwordHash;
        }

        self::validateProfileFields($data, $errors);

        return ['data' => $data, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * @param string[] $errors
     */
    private static function extractProfile(array $input, ?array &$errors = null): array
    {
        $errors = [];
        $data = [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                $errors[] = 'Registration fields must be submitted as text.';
                $data[$field] = '';
                continue;
            }

            $data[$field] = trim((string)($input[$field] ?? ''));
        }

        return $data;
    }

    /**
     * @param string[] $errors
     */
    private static function validateProfileFields(array $data, array &$errors): void
    {
        foreach (self::MAX_LENGTHS as $field => $maxLength) {
            $length = function_exists('mb_strlen')
                ? mb_strlen($data[$field], 'UTF-8')
                : strlen($data[$field]);
            if ($length > $maxLength) {
                $label = ucwords(str_replace('_', ' ', $field));
                $errors[] = $label . ' must not exceed ' . $maxLength . ' characters.';
            }
        }

        if (function_exists('mb_strlen')) {
            $fullNameLength = mb_strlen(formatFullName($data['first_name'], $data['last_name']), 'UTF-8');
        } else {
            $fullNameLength = strlen(formatFullName($data['first_name'], $data['last_name']));
        }
        if ($fullNameLength > 100) {
            $errors[] = 'Full name must not exceed 100 characters.';
        }

        foreach ([
            validateRequired($data['first_name'], 'First name'),
            validateRequired($data['last_name'], 'Last name'),
            validateEmail($data['email']),
            validatePhone($data['phone']),
            validateRequired($data['national_id'], 'National ID'),
            validateDate($data['date_of_birth'], 'Date of Birth'),
            validateRequired($data['gender'], 'Gender'),
            validateRequired($data['governorate'], 'Governorate'),
            validateRequired($data['city'], 'City'),
            validateRequired($data['address'], 'Full address'),
            validateRequired($data['emergency_contact_name'], 'Emergency contact name'),
            validateRequired($data['emergency_contact_phone'], 'Emergency contact phone'),
            validateRequired($data['emergency_contact_rel'], 'Emergency contact relationship'),
        ] as $error) {
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $nationalId = validateNationalId(
            $data['national_id'],
            $data['date_of_birth'] !== '' ? $data['date_of_birth'] : null
        );
        if (!$nationalId['valid'] && $nationalId['error'] !== null) {
            $errors[] = $nationalId['error'];
        } elseif ($nationalId['valid'] && $data['gender'] !== ($nationalId['gender'] ?? null)) {
            $errors[] = 'Gender does not match the gender encoded in the National ID.';
        }

        if ($data['gender'] !== '' && !in_array($data['gender'], ['Male', 'Female'], true)) {
            $errors[] = 'Gender must be Male or Female.';
        }

        $validBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        if ($data['blood_type'] !== '' && !in_array($data['blood_type'], $validBloodTypes, true)) {
            $errors[] = 'Invalid blood type.';
        }

        if ($data['emergency_contact_phone'] !== '') {
            $phoneError = validatePhone($data['emergency_contact_phone']);
            if ($phoneError !== null) {
                $errors[] = 'Emergency contact: ' . $phoneError;
            }
        }
    }
}
