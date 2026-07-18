<?php
/**
 * HealthBridge — Toggle Rating Visibility
 * Allows admin to hide or restore a rating.
 * Hidden ratings disappear from public doctor pages but remain visible in EMR.
 * Nothing is deleted from the database.
 *
 * POST: { id, is_hidden: 0|1 }
 *
 * Permissions: Admin only
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$input = getJsonInput();
$id = (int)($input['id'] ?? 0);
$isHidden = isset($input['is_hidden']) ? ($input['is_hidden'] ? 1 : 0) : -1;

if (!$id || $isHidden === -1) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Rating ID and is_hidden value are required.']);
}

try {
    $db = getDB();
    
    // Verify rating exists
    $stmt = $db->prepare('SELECT id FROM ratings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Rating not found.']);
    }
    
    // Toggle visibility
    $stmt = $db->prepare('UPDATE ratings SET is_hidden = ? WHERE id = ?');
    $stmt->execute([$isHidden, $id]);
    
    jsonResponse(true, [
        'message' => $isHidden ? 'Rating hidden from public view.' : 'Rating restored to public view.',
        'is_hidden' => $isHidden,
    ]);
    
} catch (Exception $e) {
    error_log('Toggle Rating Visibility Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to toggle rating visibility.']);
}
