<?php
/**
 * HealthBridge — Get Hospital Settings
 * Returns the global hospital appointment configuration.
 * - Any authenticated user (admin/doctor/patient) can read these settings.
 * - Public/unauthenticated access is also allowed (basic open/close info).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/HospitalSettingsService.php';

$role = $_SESSION['role'] ?? null;

try {
    $hs = new HospitalSettingsService(getDB());
    $settings = $hs->getSettings();
    
    // For admin: return full settings including all fields
    if ($role === 'admin') {
        jsonResponse(true, [
            'settings' => $settings,
        ]);
        exit;
    }
    
    // For doctors, patients, and public: return only open/close time, duration, and basic hospital info
    jsonResponse(true, [
        'settings' => [
            'hospital_name' => $settings['hospital_name'] ?? 'HealthBridge Hospital',
            'hospital_phone' => $settings['hospital_phone'] ?? null,
            'hospital_email' => $settings['hospital_email'] ?? null,
            'hospital_address' => $settings['hospital_address'] ?? null,
            'appointment_open_time' => $settings['appointment_open_time'] ?? '08:00',
            'appointment_close_time' => $settings['appointment_close_time'] ?? '22:00',
            'default_appointment_duration' => $settings['default_appointment_duration'] ?? 30,
        ],
    ]);
} catch (Exception $e) {
    error_log('Get Hospital Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load hospital settings.']);
}

