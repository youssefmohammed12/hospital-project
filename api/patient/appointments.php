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
 *   - status: Filter by status (upcoming|completed|cancelled|missed|all)
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
$userId = (int)$user['id'];
$role = $user['role'] ?? '';

// Only patients can access their appointments
if ($role !== 'patient') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Patient access required.']);
}

// ── Parse Query Parameters ─────────────────────────────────
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$offset = ($page - 1) * $limit;

// ── Response Caching Headers ─────────────────────────────
header('Cache-Control: private, max-age=30, must-revalidate');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 30) . ' GMT');

// ── Execute ──────────────────────────────────────────────
withDB(function (PDO $db) use ($userId, $status, $search, $limit, $offset) {
    $portal = new PatientPortalService($db);
    
    // Get all appointments with full details
    $timeline = $portal->getAppointmentTimeline($userId);
    
    // Flatten all appointments into a single array
    $allAppointments = [];
    foreach ($timeline['upcoming'] as $appt) {
        $allAppointments[] = array_merge($appt, ['category' => 'upcoming']);
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
