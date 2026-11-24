<?php
declare(strict_types=1);

/**
 * Admin-only rating visibility change.
 * The repository supports V1 and V2, but this HTTP route remains explicitly V1
 * until a separately approved runtime cutover.
 *
 * POST: { id, is_hidden: 0|1 }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/RatingRepository.php';

header('Content-Type: application/json');

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
if (!is_array($input)) {
    $input = [];
}

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0 || !array_key_exists('is_hidden', $input)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid rating ID and is_hidden value are required.']);
}

$rawHidden = $input['is_hidden'];
if (is_bool($rawHidden)) {
    $isHidden = $rawHidden ? 1 : 0;
} elseif ((is_int($rawHidden) || is_string($rawHidden)) && in_array((string)$rawHidden, ['0', '1'], true)) {
    $isHidden = (int)$rawHidden;
} else {
    http_response_code(400);
    jsonResponse(false, ['message' => 'is_hidden must be 0 or 1.']);
}

try {
    $db = getDB();
    $result = (new RatingRepository($db))->setPublicVisibility(
        (int)$id,
        $isHidden === 0,
        (int)($user['id'] ?? 0)
    );

    jsonResponse(true, [
        'message' => $result['is_hidden']
            ? 'Rating hidden from public view.'
            : 'Rating restored to public view.',
        'is_hidden' => $result['is_hidden'] ? 1 : 0,
        'is_public' => $result['is_public'] ? 1 : 0,
        'changed' => $result['changed'],
    ]);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (DomainException $e) {
    http_response_code(403);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Toggle Rating Visibility Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to toggle rating visibility.']);
}
