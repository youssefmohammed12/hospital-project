<?php
/**
 * HealthBridge — Locations API (Governorates & Cities)
 * 
 * Returns Egyptian governorates and cities for registration forms.
 *
 * GET /api/auth/locations.php?type=governorates
 * GET /api/auth/locations.php?type=cities&governorate_id=1
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$type = trim($_GET['type'] ?? '');

try {
    $db = getDB();
    
    if ($type === 'governorates') {
        $governorates = getGovernorates($db);
        jsonResponse(true, ['governorates' => $governorates]);
        
    } elseif ($type === 'cities') {
        $governorateId = (int)($_GET['governorate_id'] ?? 0);
        if ($governorateId <= 0) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Governorate ID is required.']);
        }
        
        $cities = getCitiesByGovernorate($db, $governorateId);
        jsonResponse(true, ['cities' => $cities]);
        
    } else {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Invalid type. Use "governorates" or "cities".']);
    }
    
} catch (Exception $e) {
    error_log('Locations Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load locations.']);
}