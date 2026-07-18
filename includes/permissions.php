<?php
/**
 * HealthBridge — Authorization & Role Verification Middleware
 */

/**
 * Verify the user has a specific role. Returns user data or exits with 403.
 *
 * @param string $requiredRole The role required (admin, doctor, patient)
 * @return array User data with id, role
 */
function requireRole(string $requiredRole): array {
    $user = requireAuth();
    if ($user['role'] !== $requiredRole) {
        http_response_code(403);
        jsonResponse(false, [
            'message' => 'Unauthorized. ' . ucfirst($requiredRole) . ' access required.'
        ]);
    }
    return $user;
}
