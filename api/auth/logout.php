<?php
/**
 * HealthBridge — Logout
 * Destroys the current PHP session and expires its cookie.
 */

require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

destroyAuthSession();
jsonResponse(true);
