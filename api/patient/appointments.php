<?php
/**
 * HealthBridge — Patient Appointments API (Paginated)
 *
 * Returns paginated appointments for the logged-in patient with filtering and search.
 *
 * Endpoint: GET /api/patient/appointments.php
 * Auth:     Requires patient session
 * Query Params:
 *   - page: Page number (default 1)
 *   - limit: Items per page (default 10, max 50)
 *   - status: Filter by status (upcoming|confirmed|reschedule_requested|completed|cancelled|missed|all)
 *   - search: Search term (doctor name, department, appointment ID)
 *
 * Response:
 *   success: true
 *   data: {
 *     appointments: [...],
 *     pagination: {
 *       current_page: 1,
 *       per_page: 10,
 *       total: 50,
 *       total_pages: 5
 *     },
 *     filters: {
 *       status: 'all',
 *       search: ''
 *     }
 *   }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';

// ── Authentication & Authorization ────────────────────────
$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = assertRole($user, 'patient');
$userId = (int)$user['id'];

// ── Parse Query Parameters ─────────────────────────────────
$pageValue = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
$limitValue = filter_var($_GET['limit'] ?? '10', FILTER_VALIDATE_INT);
$statusValue = $_GET['status'] ?? 'all';
$searchValue = $_GET['search'] ?? '';

if ($pageValue === false || $limitValue === false || !is_string($statusValue) || !is_string($searchValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid appointment filters.']);
}

$page = max(1, (int)$pageValue);
$limit = min(50, max(1, (int)$limitValue));
$pageIndex = $page - 1;

// Prevent integer overflow when a valid but unreasonably large page is multiplied by the page size.
if ($pageIndex > intdiv(PHP_INT_MAX, $limit)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Page value is too large.']);
}

$status = trim($statusValue);
$search = trim($searchValue);
$allowedStatuses = ['all', 'upcoming', 'confirmed', 'reschedule_requested', 'completed', 'cancelled', 'missed'];

if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid appointment status filter.']);
}

$offset = $pageIndex * $limit;

// Patient appointment data is private; keep the shared no-store response policy.

// ── Execute ──────────────────────────────────────────────
withDB(function (PDO $db) use ($userId, $status, $search, $page, $limit, $offset) {
    $portal = new PatientPortalService($db);
    
    // Get all appointments with full details
    $timeline = $portal->getAppointmentTimeline($userId);
    
    // Flatten all appointments into a single array
    $allAppointments = [];
    foreach ($timeline['upcoming'] as $appt) {
        // Upcoming appointments with status 'Confirmed' get category 'confirmed'
        // Others (Pending) stay as 'upcoming'
        $cat = ($appt['status'] === 'Confirmed') ? 'confirmed' : 'upcoming';
        $allAppointments[] = array_merge($appt, ['category' => $cat]);
    }
    foreach ($timeline['completed'] as $appt) {
        $allAppointments[] = array_merge($appt, ['category' => 'completed']);
    }
    foreach ($timeline['cancelled'] as $appt) {
        $allAppointments[] = array_merge($appt, ['category' => 'cancelled']);
    }
    foreach ($timeline['missed'] as $appt) {
        $allAppointments[] = array_merge($appt, ['category' => 'missed']);
    }
    foreach ($timeline['rescheduled'] as $appt) {
        $allAppointments[] = array_merge($appt, ['category' => 'reschedule_requested']);
    }

    // One list in date order (newest first), not grouped by status —
    // owner preference, 22 Nov 2026. Category stays on each row for
    // badges and filters.
    usort($allAppointments, function ($a, $b) {
        $ka = (string)($a['date'] ?? '') . ' ' . (string)($a['time'] ?? '');
        $kb = (string)($b['date'] ?? '') . ' ' . (string)($b['time'] ?? '');
        return strcmp($kb, $ka) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    });

    // Apply status filter
    if ($status !== 'all') {
        $allAppointments = array_filter($allAppointments, function($appt) use ($status) {
            return $appt['category'] === $status;
        });
    }
    
    // Apply search filter
    if ($search) {
        $searchLower = strtolower($search);
        $allAppointments = array_filter($allAppointments, function($appt) use ($searchLower) {
            $doctorName = strtolower($appt['doctor'] ?? '');
            $department = strtolower($appt['department'] ?? '');
            $appointmentId = strtolower((string)$appt['id']);
            
            return strpos($doctorName, $searchLower) !== false ||
                   strpos($department, $searchLower) !== false ||
                   strpos($appointmentId, $searchLower) !== false;
        });
    }
    
    // Get total count after filtering
    $total = count($allAppointments);
    $totalPages = (int)ceil($total / $limit);
    
    // Apply pagination
    $paginatedAppointments = array_slice($allAppointments, $offset, $limit);
    
    // Re-index array
    $paginatedAppointments = array_values($paginatedAppointments);
    
    return [
        'data' => [
            'appointments' => $paginatedAppointments,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => $totalPages
            ],
            'filters' => [
                'status' => $status,
                'search' => $search
            ],
            'counts' => $timeline['counts']
        ]
    ];
}, 'Failed to load appointments.');
