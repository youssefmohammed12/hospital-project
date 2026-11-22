<?php
/**
 * HealthBridge — Authorization & Role Verification Middleware
 */

/**
 * Authenticate the current request and verify the user's role.
 *
 * @param string $requiredRole The role required (admin, doctor, patient)
 * @return array Authenticated user data with id and role
 */
function requireRole(string $requiredRole): array
{
    return assertRole(requireAuth(), $requiredRole);
}

/**
 * Verify the role of a user that has already been authenticated.
 *
 * This function does not authenticate the request. Call it only with the
 * user array returned by requireAuth() in the current request.
 *
 * @param array $authenticatedUser User data returned by requireAuth()
 * @param string $requiredRole The role required (admin, doctor, patient)
 * @return array The same authenticated user data, or exits with 403
 */
function assertRole(array $authenticatedUser, string $requiredRole): array
{
    if (($authenticatedUser['role'] ?? null) !== $requiredRole) {
        http_response_code(403);
        jsonResponse(false, [
            'message' => 'Unauthorized. ' . ucfirst($requiredRole) . ' access required.'
        ]);
    }

    return $authenticatedUser;
}
