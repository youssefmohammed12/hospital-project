<?php
/**
 * HealthBridge — PatientPortalService
 *
 * Centralized service for the professional Patient Portal.
 * All aggregation is performed inside SQL for maximum efficiency.
 * Single source of truth for all patient dashboard data.
 *
 * Usage:
 *   $portal = new PatientPortalService(getDB());
 *   $data = $portal->getAll($patientId);
 *
 * @method array getAll(int $patientId) Get complete portal data
 * @method array getOverview(int $patientId) Welcome hero data
 * @method array getHealthSnapshot(int $patientId) KPI cards
 * @method array getAppointmentTimeline(int $patientId) Appointments grouped by status
 * @method array getMedicalTimeline(int $patientId) Combined chronological history
 * @method array getPrescriptions(int $patientId) Prescription cards with items
 * @method array getMedicalProfile(int $patientId) Full patient profile
 * @method array getNotifications(int $patientId) Gmail-style grouped notifications
 * @method array getFavorites(int $patientId) Auto-computed doctor recommendations
 * @method array getHealthInsights(int $patientId) Statistics and analytics
 * @method array getDownloads(int $patientId) Available downloadable documents
 * @method array getHealthAlerts(int $patientId) Active health alerts
 * @method array getProfileCompletion(int $patientId) Completion percentage + missing fields
 * @method array searchAll(int $patientId, string $query) Unified search
 */

class PatientPortalService
{
    private PDO $db;

    /**
     * @var array|null Cached hospital settings for the request
     */
    private ?array $hospitalCache = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ═══════════════════════════════════════════════════════════════
    //  GET ALL — Single entry point for the entire dashboard
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get ALL dashboard data in one call.
     * Assembles results from individual methods so each can be cached independently later.
     *
     * @param int $patientId
     * @return array Complete dashboard payload
     */
    public function getAll(int $patientId): array
    {
        return [
            'overview'            => $this->getOverview($patientId),
            'health_snapshot'     => $this->getHealthSnapshot($patientId),
            'appointments'        => $this->getAppointmentTimeline($patientId),
            'medical_timeline'    => $this->getMedicalTimeline($patientId),
            'prescriptions'       => $this->getPrescriptions($patientId),
            'profile'             => $this->getMedicalProfile($patientId),
            'notifications'       => $this->getNotifications($patientId),
            'favorites'           => $this->getFavorites($patientId),
            'insights'            => $this->getHealthInsights($patientId),
            'downloads'           => $this->getDownloads($patientId),
            'health_alerts'       => $this->getHealthAlerts($patientId),
            'profile_completion'  => $this->getProfileCompletion($patientId),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  1. OVERVIEW — Welcome Hero
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get welcome hero data: greeting, patient info, hospital status, next appointment,
     * primary doctor, unread notifications count.
     *
     * Uses a single CTE to compute hospital status and patient info together.
     */
    public function getOverview(int $patientId): array
    {
        // Hospital status — computed once per request
        $hospital = $this->getHospitalStatus();

        // Single query: patient info + next appointment + unread count + primary doctor
        $sql = "
            WITH patient_info AS (
                SELECT id, name, first_name, last_name, email, phone, patient_number, created_at
                FROM users
                WHERE id = ? AND role = 'patient'
                LIMIT 1
            ),
            next_appt AS (
                SELECT
                    a.id AS appointment_id,
                    a.doctor AS doctor_name,
                    a.doctor_id,
                    a.department,
                    a.date,
                    a.time,
                    a.appointment_time_range,
                    a.notes,
                    d.rating AS doctor_rating,
                    dep.name AS department_name,
                    (SELECT specialty FROM doctors WHERE user_id = a.doctor_id LIMIT 1) AS doctor_specialty
                FROM appointments a
                LEFT JOIN doctors d ON a.doctor_id = d.user_id
                LEFT JOIN departments dep ON a.department_id = dep.id
                WHERE a.user_id = ?
                  AND a.date >= CURDATE()
                  AND a.status != 'Cancelled'
                ORDER BY a.date ASC, a.time ASC
                LIMIT 1
            ),
            unread AS (
                SELECT COUNT(*) AS cnt FROM notifications
                WHERE user_id = ? AND is_read = 0
            ),
            primary_doctor AS (
                SELECT
                    d.name, d.specialty, d.rating, d.available,
                    dep.name AS department_name,
                    d.user_id
                FROM doctors d
                LEFT JOIN departments dep ON d.department_id = dep.id
                WHERE d.user_id = (
                    SELECT doctor_id FROM appointments
                    WHERE user_id = ?
                    GROUP BY doctor_id
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                )
                LIMIT 1
            )
            SELECT
                p.*,
                n.cnt AS unread_count,
                na.appointment_id, na.doctor_name, na.doctor_id, na.department,
                na.date AS next_date, na.time AS next_time,
                na.appointment_time_range AS next_time_range,
                na.notes AS next_notes, na.doctor_rating, na.doctor_specialty,
                na.department_name AS next_department_name,
                pd.name AS primary_doctor_name,
                pd.specialty AS primary_doctor_specialty,
                pd.rating AS primary_doctor_rating,
                pd.available AS primary_doctor_available,
                pd.department_name AS primary_doctor_dept,
                pd.user_id AS primary_doctor_user_id
            FROM patient_info p
            CROSS JOIN unread n
            LEFT JOIN next_appt na ON 1=1
            LEFT JOIN primary_doctor pd ON 1=1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $patientId, $patientId, $patientId]);
        $data = $stmt->fetch();

        if (!$data) {
            return $this->emptyOverview();
        }

        // Compute greeting based on time of day
        $hour = (int)date('G');
        $greeting = 'Good evening';
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        }

        $firstName = $data['first_name'] ?: explode(' ', $data['name'] ?? '')[0] ?: 'Patient';

        // Next appointment countdown
        $nextCountdown = null;
        if ($data['next_date']) {
            $nextCountdown = $this->computeCountdown($data['next_date'], $data['next_time']);
        }

        return [
            'greeting'              => $greeting,
            'first_name'            => $firstName,
            'full_name'             => $data['name'] ?? 'Patient',
            'patient_number'        => $data['patient_number'] ?? '-',
            'email'                 => $data['email'] ?? '',
            'phone'                 => $data['phone'] ?? '',
            'member_since'          => $data['created_at'] ?? null,
            'hospital'              => [
                'name'     => $hospital['name'],
                'is_open'  => $hospital['is_open'],
                'open_at'  => $hospital['open_time'],
                'close_at' => $hospital['close_time'],
                'status'   => $hospital['is_open'] ? 'Open' : 'Closed',
            ],
            'current_time'          => date('l, F j, Y · g:i A'),
            'current_date'          => date('Y-m-d'),
            'next_appointment'      => $data['next_date'] ? [
                'id'          => (int)$data['appointment_id'],
                'doctor'      => $data['doctor_name'],
                'doctor_id'   => (int)$data['doctor_id'],
                'doctor_specialty' => $data['doctor_specialty'],
                'department'  => $data['next_department_name'] ?: $data['department'],
                'date'        => $data['next_date'],
                'time'        => $data['next_time'],
                'time_range'  => $data['next_time_range'],
                'notes'       => $data['next_notes'],
                'countdown'   => $nextCountdown,
                'doctor_rating' => $data['doctor_rating'] ? (float)$data['doctor_rating'] : null,
            ] : null,
            'primary_doctor'        => $data['primary_doctor_name'] ? [
                'name'        => $data['primary_doctor_name'],
                'specialty'   => $data['primary_doctor_specialty'],
                'rating'      => $data['primary_doctor_rating'] ? (float)$data['primary_doctor_rating'] : null,
                'available'   => (bool)$data['primary_doctor_available'],
                'department'  => $data['primary_doctor_dept'],
                'user_id'     => (int)$data['primary_doctor_user_id'],
            ] : null,
            'unread_notifications'  => (int)$data['unread_count'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  2. HEALTH SNAPSHOT — KPI Cards
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get all KPI values in a single optimized query.
     * Returns 8+ metrics for the health snapshot section.
     */
    public function getHealthSnapshot(int $patientId): array
    {
        $sql = "
            SELECT
                -- Total appointments (all time)
                (SELECT COUNT(*) FROM appointments WHERE user_id = ?) AS total_appointments,
                -- Upcoming appointments (today or future, not cancelled)
                (SELECT COUNT(*) FROM appointments
                 WHERE user_id = ? AND date >= CURDATE() AND status != 'Cancelled') AS upcoming_appointments,
                -- Completed appointments (confirmed + past with completed workflow)
                (SELECT COUNT(*) FROM appointments a
                 JOIN visit_workflow vw ON vw.appointment_id = a.id
                 WHERE a.user_id = ? AND vw.status = 'Completed') AS completed_appointments,
                -- Active prescriptions
                (SELECT COUNT(*) FROM prescriptions
                 WHERE patient_id = ? AND status = 'Active') AS active_prescriptions,
                -- Total prescriptions
                (SELECT COUNT(*) FROM prescriptions WHERE patient_id = ?) AS total_prescriptions,
                -- Medical records count (1 if exists)
                (SELECT COUNT(*) FROM medical_records WHERE patient_id = ?) AS has_medical_record,
                -- Total notifications
                (SELECT COUNT(*) FROM notifications WHERE user_id = ?) AS total_notifications,
                -- Unread notifications
                (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) AS unread_notifications,
                -- Distinct doctors seen
                (SELECT COUNT(DISTINCT doctor_id) FROM appointments
                 WHERE user_id = ? AND status = 'Confirmed') AS doctors_seen,
                -- Last visit date
                (SELECT MAX(a.date) FROM appointments a
                 JOIN visit_workflow vw ON vw.appointment_id = a.id
                 WHERE a.user_id = ? AND vw.status = 'Completed') AS last_visit_date,
                -- Cancelled appointments
                (SELECT COUNT(*) FROM appointments
                 WHERE user_id = ? AND status = 'Cancelled') AS cancelled_appointments,
                -- Missed appointments (past confirmed without completed workflow)
                (SELECT COUNT(*) FROM appointments a
                 LEFT JOIN visit_workflow vw ON vw.appointment_id = a.id
                 WHERE a.user_id = ? AND a.date < CURDATE()
                   AND a.status IN ('Confirmed')
                   AND (vw.status IS NULL OR vw.status != 'Completed')) AS missed_appointments,
                -- Total prescriptions issued
                (SELECT COUNT(*) FROM prescriptions WHERE patient_id = ?) AS total_prescriptions_issued
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $patientId, $patientId, $patientId, $patientId,
            $patientId, $patientId, $patientId, $patientId,
            $patientId, $patientId, $patientId, $patientId,
            $patientId,
        ]);

        $data = $stmt->fetch();
        if (!$data) {
            return $this->emptySnapshot();
        }

        $completion = $this->getProfileCompletion($patientId);

        $lastVisit = $data['last_visit_date']
            ? date('M j, Y', strtotime($data['last_visit_date']))
            : null;

        return [
            'total_appointments'      => (int)$data['total_appointments'],
            'upcoming_appointments'    => (int)$data['upcoming_appointments'],
            'completed_appointments'   => (int)$data['completed_appointments'],
            'active_prescriptions'     => (int)$data['active_prescriptions'],
            'total_prescriptions'      => (int)$data['total_prescriptions'],
            'has_medical_record'       => (int)$data['has_medical_record'] > 0,
            'total_notifications'      => (int)$data['total_notifications'],
            'unread_notifications'     => (int)$data['unread_notifications'],
            'doctors_seen'             => (int)$data['doctors_seen'],
            'last_visit'               => $lastVisit,
            'cancelled_appointments'   => (int)$data['cancelled_appointments'],
            'missed_appointments'      => (int)$data['missed_appointments'],
            'total_prescriptions_issued' => (int)$data['total_prescriptions_issued'],
            'profile_completion'       => $completion['percentage'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  3. APPOINTMENT TIMELINE
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get all appointments grouped by status for the timeline.
     */
    public function getAppointmentTimeline(int $patientId): array
    {
        $sql = "
            SELECT
                a.id,
                a.doctor,
                a.doctor_id,
                a.department,
                a.department_id,
                a.date,
                a.time,
                a.appointment_time_range,
                a.notes,
                a.status,
                a.created_at AS booked_at,
                d.rating AS doctor_rating,
                d.specialty AS doctor_specialty,
                d.available AS doctor_available,
                dep.name AS department_name,
                vw.status AS workflow_status,
                vw.completed_at,
                p.id AS prescription_id,
                p.status AS prescription_status,
                r.id AS rating_id,
                r.stars AS rating_stars
            FROM appointments a
            LEFT JOIN doctors d ON a.doctor_id = d.user_id
            LEFT JOIN departments dep ON a.department_id = dep.id
            LEFT JOIN visit_workflow vw ON vw.appointment_id = a.id
            LEFT JOIN prescriptions p ON p.appointment_id = a.id
            LEFT JOIN ratings r ON r.appointment_id = a.id
            WHERE a.user_id = ?
            ORDER BY a.date DESC, a.time DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $appointments = $stmt->fetchAll();

        $today = date('Y-m-d');
        $upcoming = [];
        $completed = [];
        $cancelled = [];
        $missed = [];

        foreach ($appointments as $a) {
            $item = [
                'id'                 => (int)$a['id'],
                'doctor'             => $a['doctor'],
                'doctor_id'          => (int)$a['doctor_id'],
                'doctor_rating'      => $a['doctor_rating'] ? (float)$a['doctor_rating'] : null,
                'doctor_specialty'   => $a['doctor_specialty'],
                'doctor_available'   => (bool)$a['doctor_available'],
                'department'         => $a['department_name'] ?: $a['department'],
                'department_id'      => (int)$a['department_id'],
                'date'               => $a['date'],
                'time'               => $a['time'],
                'time_range'         => $a['appointment_time_range'],
                'notes'              => $a['notes'],
                'status'             => $a['status'],
                'booked_at'          => $a['booked_at'],
                'workflow_status'    => $a['workflow_status'],
                'completed_at'       => $a['completed_at'],
                'has_prescription'   => (bool)$a['prescription_id'],
                'prescription_id'    => (int)$a['prescription_id'],
                'prescription_status' => $a['prescription_status'],
                'has_rating'         => (bool)$a['rating_id'],
                'rating_stars'       => $a['rating_stars'] ? (int)$a['rating_stars'] : null,
                'can_rate'           => $a['date'] < $today && $a['status'] === 'Confirmed' && !$a['rating_id'],
            ];

            if ($a['status'] === 'Cancelled') {
                $cancelled[] = $item;
            } elseif ($a['date'] < $today && $a['status'] === 'Confirmed') {
                if ($a['workflow_status'] === 'Completed') {
                    $completed[] = $item;
                } else {
                    $missed[] = $item;
                }
            } elseif ($a['date'] >= $today && $a['status'] !== 'Cancelled') {
                $upcoming[] = $item;
            } elseif ($a['status'] === 'Pending') {
                $upcoming[] = $item;
            }
        }

        return [
            'upcoming'  => $upcoming,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'missed'    => $missed,
            'counts'    => [
                'upcoming'  => count($upcoming),
                'completed' => count($completed),
                'cancelled' => count($cancelled),
                'missed'    => count($missed),
                'total'     => count($appointments),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  4. MEDICAL TIMELINE — Combined Chronological History
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build a unified chronological timeline of all patient events.
     */
    public function getMedicalTimeline(int $patientId): array
    {
        $regSql = "SELECT created_at FROM users WHERE id = ? LIMIT 1";
        $regStmt = $this->db->prepare($regSql);
        $regStmt->execute([$patientId]);
        $reg = $regStmt->fetch();

        $events = [];

        if ($reg) {
            $events[] = [
                'date'        => $reg['created_at'],
                'type'        => 'registration',
                'title'       => 'Patient Registered',
                'description' => 'Account created successfully.',
                'icon'        => 'fa-user-plus',
                'color'       => 'var(--primary)',
                'metadata'    => null,
            ];
        }

        $sql = "
            SELECT
                a.id AS appointment_id,
                a.date,
                a.time,
                a.appointment_time_range,
                a.status AS appointment_status,
                a.doctor,
                a.department,
                vw.status AS workflow_status,
                vw.completed_at,
                vn.id AS visit_note_id,
                vn.diagnosis,
                vn.symptoms,
                vn.treatment,
                vn.doctor_notes,
                vn.created_at AS visit_note_created_at,
                p.id AS prescription_id,
                p.status AS prescription_status,
                p.created_at AS prescription_created_at,
                r.id AS rating_id,
                r.stars,
                r.review,
                r.created_at AS rating_created_at
            FROM appointments a
            LEFT JOIN visit_workflow vw ON vw.appointment_id = a.id
            LEFT JOIN visit_notes vn ON vn.appointment_id = a.id
            LEFT JOIN prescriptions p ON p.appointment_id = a.id
            LEFT JOIN ratings r ON r.appointment_id = a.id
            WHERE a.user_id = ?
            ORDER BY a.date ASC, a.time ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $appointments = $stmt->fetchAll();

        foreach ($appointments as $a) {
            $apptDate = $a['date'] . ' ' . $a['time'];

            $events[] = [
                'date'        => $apptDate,
                'type'        => 'appointment_booked',
                'title'       => 'Appointment Booked',
                'description' => "{$a['department']} with {$a['doctor']} — " . ($a['appointment_time_range'] ?: $a['time']),
                'icon'        => 'fa-calendar-check',
                'color'       => $a['appointment_status'] === 'Cancelled' ? 'var(--danger)' : 'var(--primary)',
                'metadata'    => [
                    'appointment_id' => (int)$a['appointment_id'],
                    'status'         => $a['appointment_status'],
                    'doctor'         => $a['doctor'],
                    'department'     => $a['department'],
                ],
            ];

            if ($a['workflow_status'] === 'Completed') {
                $visitDate = $a['completed_at'] ?: $apptDate;
                $events[] = [
                    'date'        => $visitDate,
                    'type'        => 'visit_completed',
                    'title'       => 'Visit Completed',
                    'description' => "Consultation with {$a['doctor']} — {$a['department']}" .
                        ($a['diagnosis'] ? ". Diagnosis: {$a['diagnosis']}" : ''),
                    'icon'        => 'fa-stethoscope',
                    'color'       => 'var(--success)',
                    'metadata'    => [
                        'appointment_id' => (int)$a['appointment_id'],
                        'diagnosis'      => $a['diagnosis'],
                        'symptoms'       => $a['symptoms'],
                        'treatment'      => $a['treatment'],
                        'notes'          => $a['doctor_notes'],
                        'has_visit_note' => (bool)$a['visit_note_id'],
                    ],
                ];
            }

            if ($a['prescription_id']) {
                $events[] = [
                    'date'        => $a['prescription_created_at'] ?: $apptDate,
                    'type'        => 'prescription_issued',
                    'title'       => 'Prescription Issued',
                    'description' => "Prescription by {$a['doctor']} — Status: {$a['prescription_status']}",
                    'icon'        => 'fa-prescription',
                    'color'       => $a['prescription_status'] === 'Active' ? 'var(--warning)' : 'var(--text-muted)',
                    'metadata'    => [
                        'prescription_id'   => (int)$a['prescription_id'],
                        'status'            => $a['prescription_status'],
                        'appointment_id'    => (int)$a['appointment_id'],
                    ],
                ];
            }

            if ($a['rating_id']) {
                $events[] = [
                    'date'        => $a['rating_created_at'] ?: $apptDate,
                    'type'        => 'rating_submitted',
                    'title'       => 'Rating Submitted',
                    'description' => "Rated {$a['doctor']} {$a['stars']} star" . ($a['stars'] > 1 ? 's' : '') .
                        ($a['review'] ? ': "' . mb_substr($a['review'], 0, 100) . '"' : ''),
                    'icon'        => 'fa-star',
                    'color'       => '#facc15',
                    'metadata'    => [
                        'rating_id' => (int)$a['rating_id'],
                        'stars'     => (int)$a['stars'],
                        'review'    => $a['review'],
                    ],
                ];
            }
        }

        $auditSql = "
            SELECT created_at, description, entity_type, entity_id
            FROM admin_audit
            WHERE patient_id = ?
              AND entity_type = 'patient'
              AND action IN ('update', 'create')
            ORDER BY created_at ASC
        ";
        $auditStmt = $this->db->prepare($auditSql);
        $auditStmt->execute([$patientId]);
        $audits = $auditStmt->fetchAll();

        foreach ($audits as $a) {
            $events[] = [
                'date'        => $a['created_at'],
                'type'        => 'record_updated',
                'title'       => 'Medical Record Updated',
                'description' => $a['description'] ?: 'Profile information was updated.',
                'icon'        => 'fa-notes-medical',
                'color'       => 'var(--primary-dark)',
                'metadata'    => [
                    'audit_id'    => (int)$a['entity_id'],
                    'entity_type' => $a['entity_type'],
                ],
            ];
        }

        usort($events, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        return $events;
    }

    // ═══════════════════════════════════════════════════════════════
    //  5. PRESCRIPTIONS — Cards with Medication Tracker
    // ═══════════════════════════════════════════════════════════════

    public function getPrescriptions(int $patientId): array
    {
        $sql = "
            SELECT
                p.id, p.patient_id, p.doctor_id, p.appointment_id,
                p.notes, p.status, p.created_at, p.updated_at,
                u_doctor.name AS doctor_name,
                a.date AS appt_date,
                a.department AS appt_department,
                (SELECT specialty FROM doctors WHERE user_id = p.doctor_id LIMIT 1) AS doctor_specialty,
                (SELECT rating FROM doctors WHERE user_id = p.doctor_id LIMIT 1) AS doctor_rating
            FROM prescriptions p
            JOIN users u_doctor ON p.doctor_id = u_doctor.id
            JOIN appointments a ON p.appointment_id = a.id
            WHERE p.patient_id = ?
            ORDER BY p.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $prescriptions = $stmt->fetchAll();

        $prescriptionIds = array_column($prescriptions, 'id');
        $itemsByPrescription = [];

        if (!empty($prescriptionIds)) {
            $placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));
            $itemSql = "
                SELECT prescription_id, id, medication_name, strength, dosage, frequency, duration, instructions, sort_order
                FROM prescription_items
                WHERE prescription_id IN ($placeholders)
                ORDER BY sort_order ASC
            ";
            $itemStmt = $this->db->prepare($itemSql);
            $itemStmt->execute($prescriptionIds);
            $items = $itemStmt->fetchAll();

            foreach ($items as $item) {
                $pid = (int)$item['prescription_id'];
                if (!isset($itemsByPrescription[$pid])) {
                    $itemsByPrescription[$pid] = [];
                }
                $itemsByPrescription[$pid][] = [
                    'id'               => (int)$item['id'],
                    'medication_name'  => $item['medication_name'],
                    'strength'         => $item['strength'],
                    'dosage'           => $item['dosage'],
                    'frequency'        => $item['frequency'],
                    'duration'         => $item['duration'],
                    'instructions'     => $item['instructions'],
                    'sort_order'       => (int)$item['sort_order'],
                ];
            }
        }

        $result = [];
        foreach ($prescriptions as $p) {
            $pid = (int)$p['id'];
            $result[] = [
                'id'                => $pid,
                'doctor_name'       => $p['doctor_name'],
                'doctor_id'         => (int)$p['doctor_id'],
                'doctor_specialty'  => $p['doctor_specialty'],
                'doctor_rating'     => $p['doctor_rating'] ? (float)$p['doctor_rating'] : null,
                'appointment_id'    => (int)$p['appointment_id'],
                'appointment_date'  => $p['appt_date'],
                'department'        => $p['appt_department'],
                'notes'             => $p['notes'],
                'status'            => $p['status'],
                'created_at'        => $p['created_at'],
                'updated_at'        => $p['updated_at'],
                'items'             => $itemsByPrescription[$pid] ?? [],
                'items_count'       => count($itemsByPrescription[$pid] ?? []),
            ];
        }

        $activeMeds = [];
        foreach ($result as $rx) {
            if ($rx['status'] === 'Active') {
                foreach ($rx['items'] as $item) {
                    $activeMeds[] = [
                        'medication'  => $item['medication_name'],
                        'strength'    => $item['strength'],
                        'dosage'      => $item['dosage'],
                        'frequency'   => $item['frequency'],
                        'duration'    => $item['duration'],
                        'doctor'      => $rx['doctor_name'],
                        'prescribed'  => $rx['created_at'],
                        'expiry_date' => null,
                        'refills_left' => null,
                    ];
                }
            }
        }

        return [
            'prescriptions'      => $result,
            'active_medications' => $activeMeds,
            'counts'             => [
                'total'  => count($result),
                'active' => count(array_filter($result, fn($r) => $r['status'] === 'Active')),
                'completed' => count(array_filter($result, fn($r) => $r['status'] === 'Completed')),
                'cancelled' => count(array_filter($result, fn($r) => $r['status'] === 'Cancelled')),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  6. MEDICAL PROFILE — Full Patient Profile
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the complete patient medical profile.
     */
    public function getMedicalProfile(int $patientId): array
    {
        $sql = "
            SELECT
                u.id, u.name, u.first_name, u.last_name, u.email, u.phone,
                u.patient_number, u.national_id, u.created_at AS registered_at,
                mr.blood_type, mr.height_cm, mr.weight_kg,
                mr.date_of_birth, mr.gender,
                mr.allergies, mr.chronic_diseases,
                mr.current_medications, mr.previous_surgeries, mr.family_history,
                mr.emergency_contact_name, mr.emergency_contact_rel, mr.emergency_contact_phone,
                mr.governorate, mr.city, mr.address,
                mr.insurance_provider, mr.insurance_number,
                mr.medical_notes
            FROM users u
            LEFT JOIN medical_records mr ON u.id = mr.patient_id
            WHERE u.id = ? AND u.role = 'patient'
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $data = $stmt->fetch();

        if (!$data) {
            return $this->emptyProfile();
        }

        $age = null;
        if ($data['date_of_birth']) {
            $dob = new DateTime($data['date_of_birth']);
            $now = new DateTime();
            $age = $dob->diff($now)->y;
        }

        $bmi = null;
        if ($data['height_cm'] && $data['weight_kg'] && (float)$data['height_cm'] > 0) {
            $heightM = (float)$data['height_cm'] / 100;
            $weightKg = (float)$data['weight_kg'];
            $bmi = round($weightKg / ($heightM * $heightM), 1);
        }

        return [
            'id'                    => (int)$data['id'],
            'name'                  => $data['name'],
            'first_name'            => $data['first_name'],
            'last_name'             => $data['last_name'],
            'email'                 => $data['email'],
            'phone'                 => $data['phone'],
            'patient_number'        => $data['patient_number'] ?? '-',
            'national_id'           => $data['national_id'],
            'registered_at'         => $data['registered_at'],
            'date_of_birth'         => $data['date_of_birth'],
            'age'                   => $age,
            'gender'                => $data['gender'],
            'blood_type'            => $data['blood_type'],
            'height_cm'             => $data['height_cm'] ? (float)$data['height_cm'] : null,
            'weight_kg'             => $data['weight_kg'] ? (float)$data['weight_kg'] : null,
            'bmi'                   => $bmi,
            'allergies'             => $data['allergies'],
            'allergies_list'        => $this->parseListField($data['allergies']),
            'chronic_diseases'      => $data['chronic_diseases'],
            'chronic_diseases_list' => $this->parseListField($data['chronic_diseases']),
            'current_medications'   => $data['current_medications'],
            'previous_surgeries'    => $data['previous_surgeries'],
            'family_history'        => $data['family_history'],
            'emergency_contact'     => [
                'name'  => $data['emergency_contact_name'],
                'relationship' => $data['emergency_contact_rel'],
                'phone' => $data['emergency_contact_phone'],
            ],
            'location'              => [
                'governorate' => $data['governorate'],
                'city'        => $data['city'],
                'address'     => $data['address'],
            ],
            'insurance'             => [
                'provider' => $data['insurance_provider'],
                'number'   => $data['insurance_number'],
            ],
            'medical_notes'         => $data['medical_notes'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  7. NOTIFICATIONS — Gmail-Style Grouped
    // ═══════════════════════════════════════════════════════════════

    public function getNotifications(int $patientId, int $limit = 50): array
    {
        $sql = "
            SELECT id, type, title, message, ref_type, ref_id, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $limit]);
        $notifications = $stmt->fetchAll();

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $thisWeekStart = date('Y-m-d', strtotime('monday this week'));

        $groups = ['today' => [], 'yesterday' => [], 'this_week' => [], 'earlier' => []];
        $typeGroups = ['appointments' => [], 'medical' => [], 'system' => [], 'other' => []];

        $appointmentTypes = ['appointment_confirmed', 'appointment_declined', 'appointment_request',
                             'appointment_cancelled', 'appointment_time_changed', 'appointment_completed'];
        $medicalTypes = ['prescription_issued', 'prescription_updated', 'prescription_completed',
                         'prescription_cancelled', 'medical_record_updated', 'visit_note_added',
                         'rating_received', 'review_received'];

        foreach ($notifications as $n) {
            $notifDate = date('Y-m-d', strtotime($n['created_at']));
            $group = 'earlier';
            if ($notifDate === $today) $group = 'today';
            elseif ($notifDate === $yesterday) $group = 'yesterday';
            elseif (strtotime($notifDate) >= strtotime($thisWeekStart)) $group = 'this_week';

            $item = [
                'id'         => (int)$n['id'],
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'ref_type'   => $n['ref_type'],
                'ref_id'     => $n['ref_id'] ? (int)$n['ref_id'] : null,
                'is_read'    => (bool)$n['is_read'],
                'created_at' => $n['created_at'],
                'time_ago'   => $this->relativeTime($n['created_at']),
                'group'      => $group,
            ];

            $groups[$group][] = $item;

            if (in_array($n['type'], $appointmentTypes)) $typeGroups['appointments'][] = $item;
            elseif (in_array($n['type'], $medicalTypes)) $typeGroups['medical'][] = $item;
            elseif (strpos($n['type'], 'password') !== false || strpos($n['type'], 'account') !== false) $typeGroups['system'][] = $item;
            else $typeGroups['other'][] = $item;
        }

        $unreadStmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unreadStmt->execute([$patientId]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        return [
            'grouped' => $groups,
            'by_type' => $typeGroups,
            'unread_count' => $unreadCount,
            'total' => count($notifications),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  8. FAVORITE DOCTORS — Auto-Computed
    // ═══════════════════════════════════════════════════════════════

    public function getFavorites(int $patientId): array
    {
        $mostVisitedSql = "
            SELECT a.doctor_id, u.name AS doctor_name, d.specialty, d.rating AS doctor_rating,
                   d.available, dep.name AS department_name, COUNT(*) AS visit_count, MAX(a.date) AS last_visit_date
            FROM appointments a
            JOIN users u ON a.doctor_id = u.id
            JOIN doctors d ON a.doctor_id = d.user_id
            LEFT JOIN departments dep ON a.department_id = dep.id
            WHERE a.user_id = ? AND a.status = 'Confirmed'
            GROUP BY a.doctor_id, u.name, d.specialty, d.rating, d.available, dep.name
            ORDER BY visit_count DESC LIMIT 3
        ";
        $stmt = $this->db->prepare($mostVisitedSql);
        $stmt->execute([$patientId]);
        $mostVisited = $stmt->fetchAll();

        $topRatedSql = "
            SELECT r.doctor_id, u.name AS doctor_name, d.specialty, d.rating AS doctor_rating,
                   d.available, dep.name AS department_name, AVG(r.stars) AS patient_avg_rating, COUNT(r.id) AS rating_count
            FROM ratings r
            JOIN appointments a ON r.appointment_id = a.id
            JOIN users u ON r.doctor_id = u.id
            JOIN doctors d ON r.doctor_id = d.user_id
            LEFT JOIN departments dep ON a.department_id = dep.id
            WHERE r.user_id = ?
            GROUP BY r.doctor_id, u.name, d.specialty, d.rating, d.available, dep.name
            ORDER BY patient_avg_rating DESC LIMIT 3
        ";
        $stmt = $this->db->prepare($topRatedSql);
        $stmt->execute([$patientId]);
        $topRated = $stmt->fetchAll();

        $recentSql = "
            SELECT a.doctor_id, u.name AS doctor_name, d.specialty, d.rating AS doctor_rating,
                   d.available, dep.name AS department_name, MAX(a.date) AS last_visit_date
            FROM appointments a
            JOIN users u ON a.doctor_id = u.id
            JOIN doctors d ON a.doctor_id = d.user_id
            LEFT JOIN departments dep ON a.department_id = dep.id
            WHERE a.user_id = ? AND a.status = 'Confirmed'
            GROUP BY a.doctor_id, u.name, d.specialty, d.rating, d.available, dep.name
            ORDER BY last_visit_date DESC LIMIT 3
        ";
        $stmt = $this->db->prepare($recentSql);
        $stmt->execute([$patientId]);
        $recentVisited = $stmt->fetchAll();

        $recommendedSql = "
            SELECT d.id, d.user_id, d.name AS doctor_name, d.specialty, d.rating, d.available, d.exp, dep.name AS department_name
            FROM doctors d
            LEFT JOIN departments dep ON d.department_id = dep.id
            WHERE d.department_id = (
                SELECT a.department_id FROM appointments a WHERE a.user_id = ? AND a.department_id IS NOT NULL
                GROUP BY a.department_id ORDER BY COUNT(*) DESC LIMIT 1
            )
            AND d.user_id NOT IN (SELECT DISTINCT a.doctor_id FROM appointments a WHERE a.user_id = ?)
            AND d.available = 1
            ORDER BY d.rating DESC LIMIT 3
        ";
        $stmt = $this->db->prepare($recommendedSql);
        $stmt->execute([$patientId, $patientId]);
        $recommended = $stmt->fetchAll();

        $formatDoctor = fn($d) => [
            'doctor_id'       => (int)($d['doctor_id'] ?? $d['user_id']),
            'name'            => $d['doctor_name'] ?? $d['name'],
            'specialty'       => $d['specialty'],
            'rating'          => (float)($d['doctor_rating'] ?? $d['rating']),
            'available'       => (bool)($d['available']),
            'department'      => $d['department_name'],
            'visit_count'     => isset($d['visit_count']) ? (int)$d['visit_count'] : null,
            'last_visit'      => $d['last_visit_date'] ?? null,
            'patient_rating'  => isset($d['patient_avg_rating']) ? round((float)$d['patient_avg_rating'], 1) : null,
            'experience'      => isset($d['exp']) ? (int)$d['exp'] : null,
        ];

        return [
            'most_visited'       => array_map($formatDoctor, $mostVisited),
            'top_rated'          => array_map($formatDoctor, $topRated),
            'recently_visited'   => array_map($formatDoctor, $recentVisited),
            'recommended'        => array_map($formatDoctor, $recommended),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  9. HEALTH INSIGHTS — Statistics & Analytics
    // ═══════════════════════════════════════════════════════════════

    public function getHealthInsights(int $patientId): array
    {
        $topDeptSql = "
            SELECT COALESCE(dep.name, a.department) AS department_name, COUNT(*) AS visit_count
            FROM appointments a
            LEFT JOIN departments dep ON a.department_id = dep.id
            WHERE a.user_id = ? AND a.status = 'Confirmed'
            GROUP BY COALESCE(dep.name, a.department)
            ORDER BY visit_count DESC LIMIT 1
        ";
        $stmt = $this->db->prepare($topDeptSql);
        $stmt->execute([$patientId]);
        $topDept = $stmt->fetch();

        $statsSql = "
            SELECT COUNT(*) AS total_visits,
                   SUM(CASE WHEN vw.status = 'Completed' THEN 1 ELSE 0 END) AS completed_visits,
                   SUM(CASE WHEN a.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_visits,
                   COUNT(DISTINCT a.doctor_id) AS unique_doctors,
                   AVG(r.stars) AS avg_rating_given,
                   COUNT(r.id) AS ratings_count,
                   COUNT(DISTINCT p.id) AS prescriptions_this_year
            FROM appointments a
            LEFT JOIN visit_workflow vw ON vw.appointment_id = a.id
            LEFT JOIN ratings r ON r.appointment_id = a.id
            LEFT JOIN prescriptions p ON p.appointment_id = a.id AND YEAR(p.created_at) = YEAR(CURDATE())
            WHERE a.user_id = ?
        ";
        $stmt = $this->db->prepare($statsSql);
        $stmt->execute([$patientId]);
        $stats = $stmt->fetch();

        $monthSql = "
            SELECT COUNT(*) AS visits_this_month
            FROM appointments WHERE user_id = ? AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE()) AND status = 'Confirmed'
        ";
        $stmt = $this->db->prepare($monthSql);
        $stmt->execute([$patientId]);
        $monthData = $stmt->fetch();

        $monthlySql = "
            SELECT DATE_FORMAT(date, '%Y-%m') AS month, COUNT(*) AS count,
                   SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled
            FROM appointments WHERE user_id = ? AND date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
            GROUP BY DATE_FORMAT(date, '%Y-%m') ORDER BY month ASC
        ";
        $stmt = $this->db->prepare($monthlySql);
        $stmt->execute([$patientId]);
        $monthly = $stmt->fetchAll();

        $intervalSql = "
            SELECT a.date AS visit_date FROM appointments a
            WHERE a.user_id = ? AND a.status = 'Confirmed' ORDER BY a.date ASC
        ";
        $stmt = $this->db->prepare($intervalSql);
        $stmt->execute([$patientId]);
        $visitDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $avgInterval = null;
        if (count($visitDates) >= 2) {
            $totalDays = 0;
            for ($i = 1; $i < count($visitDates); $i++) {
                $totalDays += abs(strtotime($visitDates[$i]) - strtotime($visitDates[$i - 1])) / 86400;
            }
            $avgInterval = round($totalDays / (count($visitDates) - 1), 1);
        }

        $deptDistSql = "
            SELECT COALESCE(dep.name, a.department) AS name, COUNT(*) AS count
            FROM appointments a LEFT JOIN departments dep ON a.department_id = dep.id
            WHERE a.user_id = ? AND a.status = 'Confirmed' GROUP BY name ORDER BY count DESC
        ";
        $stmt = $this->db->prepare($deptDistSql);
        $stmt->execute([$patientId]);
        $deptDist = $stmt->fetchAll();

        $totalVisits = (int)($stats['total_visits'] ?? 0);
        $completedVisits = (int)($stats['completed_visits'] ?? 0);
        $cancelledVisits = (int)($stats['cancelled_visits'] ?? 0);

        $attendanceRate = $totalVisits > 0 ? round((($totalVisits - $cancelledVisits) / $totalVisits) * 100, 1) : null;
        $confirmedVisits = $totalVisits - $cancelledVisits;
        $completionRate = $confirmedVisits > 0 ? round(($completedVisits / $confirmedVisits) * 100, 1) : null;

        return [
            'most_visited_department'   => $topDept ? ['name' => $topDept['department_name'], 'count' => (int)$topDept['visit_count']] : null,
            'appointment_attendance_rate' => $attendanceRate,
            'appointment_completion_rate'  => $completionRate,
            'average_doctor_rating_given'  => $stats['avg_rating_given'] ? round((float)$stats['avg_rating_given'], 1) : null,
            'ratings_count'               => (int)($stats['ratings_count'] ?? 0),
            'unique_doctors_visited'      => (int)($stats['unique_doctors'] ?? 0),
            'prescriptions_this_year'     => (int)($stats['prescriptions_this_year'] ?? 0),
            'visits_this_month'           => (int)($monthData['visits_this_month'] ?? 0),
            'total_visits'                => $totalVisits,
            'completed_visits'            => $completedVisits,
            'cancelled_visits'            => $cancelledVisits,
            'average_interval_days'       => $avgInterval,
            'monthly_visits'              => array_map(fn($m) => ['month' => $m['month'], 'count' => (int)$m['count'], 'cancelled' => (int)$m['cancelled']], $monthly),
            'department_distribution'     => array_map(fn($d) => ['name' => $d['name'], 'count' => (int)$d['count']], $deptDist),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  10. DOWNLOADS — Document Center
    // ═══════════════════════════════════════════════════════════════

    public function getDownloads(int $patientId): array
    {
        $rxSql = "
            SELECT p.id, u.name AS doctor_name, a.date AS appointment_date, a.department, p.status, p.created_at,
                   (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = p.id) AS items_count
            FROM prescriptions p
            JOIN users u ON p.doctor_id = u.id
            JOIN appointments a ON p.appointment_id = a.id
            WHERE p.patient_id = ? ORDER BY p.created_at DESC
        ";
        $stmt = $this->db->prepare($rxSql);
        $stmt->execute([$patientId]);
        $prescriptions = $stmt->fetchAll();

        $confSql = "
            SELECT id, doctor, department, date, appointment_time_range, status, created_at
            FROM appointments WHERE user_id = ? AND status IN ('Confirmed') ORDER BY date DESC
        ";
        $stmt = $this->db->prepare($confSql);
        $stmt->execute([$patientId]);
        $confirmations = $stmt->fetchAll();

        $visitSql = "
            SELECT a.id AS appointment_id, a.doctor, a.department, a.date, a.appointment_time_range, a.status,
                   vn.diagnosis, vn.symptoms, vn.treatment, vn.created_at AS summary_date
            FROM appointments a
            JOIN visit_workflow vw ON vw.appointment_id = a.id
            LEFT JOIN visit_notes vn ON vn.appointment_id = a.id
            WHERE a.user_id = ? AND vw.status = 'Completed' ORDER BY a.date DESC
        ";
        $stmt = $this->db->prepare($visitSql);
        $stmt->execute([$patientId]);
        $visits = $stmt->fetchAll();

        return [
            'prescriptions' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'type' => 'prescription', 'label' => "Prescription #{$r['id']}",
                'doctor' => $r['doctor_name'], 'department' => $r['department'], 'date' => $r['appointment_date'],
                'created_at' => $r['created_at'], 'status' => $r['status'], 'items_count' => (int)$r['items_count'],
                'url' => "api/prescriptions/print.php?id={$r['id']}",
            ], $prescriptions),
            'confirmations' => array_map(fn($a) => [
                'id' => (int)$a['id'], 'type' => 'confirmation', 'label' => "Appointment Confirmation #{$a['id']}",
                'doctor' => $a['doctor'], 'department' => $a['department'], 'date' => $a['date'],
                'time' => $a['appointment_time_range'], 'status' => $a['status'],
                'url' => "api/appointments/print.php?id={$a['id']}",
            ], $confirmations),
            'visit_summaries' => array_map(fn($v) => [
                'id' => (int)$v['appointment_id'], 'type' => 'visit_summary', 'label' => "Visit Summary — {$v['doctor']}",
                'doctor' => $v['doctor'], 'department' => $v['department'], 'date' => $v['date'],
                'time' => $v['appointment_time_range'], 'diagnosis' => $v['diagnosis'],
                'symptoms' => $v['symptoms'], 'treatment' => $v['treatment'],
                'url' => "api/appointments/print.php?id={$v['appointment_id']}&type=summary",
            ], $visits),
            'available_types' => ['prescription', 'confirmation', 'visit_summary', 'lab_report', 'imaging_report', 'insurance_claim', 'invoice', 'discharge_summary'],
            'counts' => [
                'prescriptions' => count($prescriptions), 'confirmations' => count($confirmations),
                'visit_summaries' => count($visits), 'total' => count($prescriptions) + count($confirmations) + count($visits),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  11. HEALTH ALERTS — Dedicated Widget
    // ═══════════════════════════════════════════════════════════════

    public function getHealthAlerts(int $patientId): array
    {
        $alerts = [];

        $upcomingSql = "
            SELECT id, doctor, department, date, appointment_time_range
            FROM appointments WHERE user_id = ? AND date = CURDATE() AND status != 'Cancelled'
            ORDER BY time ASC LIMIT 1
        ";
        $stmt = $this->db->prepare($upcomingSql);
        $stmt->execute([$patientId]);
        $todayAppt = $stmt->fetch();
        if ($todayAppt) {
            $alerts[] = ['type' => 'appointment_today', 'severity' => 'info', 'icon' => 'fa-calendar-day',
                'title' => 'Appointment Today',
                'message' => "You have an appointment with {$todayAppt['doctor']} in {$todayAppt['department']} at " . ($todayAppt['appointment_time_range'] ?: $todayAppt['time']) . ".",
                'action' => ['label' => 'View Details', 'url' => '#appointments']];
        }

        $tomorrowSql = "
            SELECT id, doctor, department, date, appointment_time_range
            FROM appointments WHERE user_id = ? AND date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status != 'Cancelled'
            ORDER BY time ASC LIMIT 1
        ";
        $stmt = $this->db->prepare($tomorrowSql);
        $stmt->execute([$patientId]);
        $tomorrowAppt = $stmt->fetch();
        if ($tomorrowAppt) {
            $alerts[] = ['type' => 'appointment_tomorrow', 'severity' => 'warning', 'icon' => 'fa-calendar-day',
                'title' => 'Appointment Tomorrow',
                'message' => "You have an appointment with {$tomorrowAppt['doctor']} at " . ($tomorrowAppt['appointment_time_range'] ?: $tomorrowAppt['time']) . ".",
                'action' => ['label' => 'View Details', 'url' => '#appointments']];
        }

        $expiringSql = "SELECT COUNT(*) AS cnt FROM prescriptions WHERE patient_id = ? AND status = 'Active' AND created_at <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $this->db->prepare($expiringSql);
        $stmt->execute([$patientId]);
        $expiringCount = (int)$stmt->fetchColumn();
        if ($expiringCount > 0) {
            $alerts[] = ['type' => 'prescription_old', 'severity' => 'warning', 'icon' => 'fa-prescription',
                'title' => 'Active Prescription' . ($expiringCount > 1 ? 's' : '') . ' Need Review',
                'message' => "You have {$expiringCount} active prescription" . ($expiringCount > 1 ? 's that have' : ' that has') . " been active for over 30 days.",
                'action' => ['label' => 'View Prescriptions', 'url' => '#prescriptions']];
        }

        $completion = $this->getProfileCompletion($patientId);
        if ($completion['percentage'] < 100) {
            $missingFields = array_slice($completion['missing_fields'], 0, 3);
            $alerts[] = ['type' => 'incomplete_profile', 'severity' => $completion['percentage'] < 50 ? 'danger' : 'info', 'icon' => 'fa-user-pen',
                'title' => 'Complete Your Profile',
                'message' => "Your profile is {$completion['percentage']}% complete. Missing: " . implode(', ', array_map(fn($f) => $f['label'], $missingFields)) . ".",
                'action' => ['label' => 'Complete Profile', 'url' => '#profile']];
        }

        $hospital = $this->getHospitalStatus();
        if (!$hospital['is_open']) {
            $alerts[] = ['type' => 'hospital_closed', 'severity' => 'info', 'icon' => 'fa-hospital',
                'title' => 'Hospital Closed Today',
                'message' => "{$hospital['name']} is currently closed. Operating hours: {$hospital['open_time']} – {$hospital['close_time']}.",
                'action' => null];
        }

        $unreadStmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unreadStmt->execute([$patientId]);
        $unread = (int)$unreadStmt->fetchColumn();
        if ($unread > 0) {
            $alerts[] = ['type' => 'unread_notifications', 'severity' => 'info', 'icon' => 'fa-bell',
                'title' => 'Unread Notifications', 'message' => "You have {$unread} unread notification" . ($unread > 1 ? 's' : '') . ".",
                'action' => ['label' => 'View Notifications', 'url' => '#notifications']];
        }

        $ecSql = "SELECT emergency_contact_name FROM medical_records WHERE patient_id = ?";
        $stmt = $this->db->prepare($ecSql);
        $stmt->execute([$patientId]);
        $ec = $stmt->fetch();
        if ($ec && empty($ec['emergency_contact_name'])) {
            $alerts[] = ['type' => 'missing_emergency_contact', 'severity' => 'danger', 'icon' => 'fa-phone',
                'title' => 'Emergency Contact Missing',
                'message' => 'Please add an emergency contact to your profile.',
                'action' => ['label' => 'Add Now', 'url' => '#profile']];
        }

        $severityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => ($severityOrder[$a['severity']] ?? 2) - ($severityOrder[$b['severity']] ?? 2));

        return ['alerts' => $alerts, 'count' => count($alerts), 'has_critical' => count(array_filter($alerts, fn($a) => $a['severity'] === 'danger')) > 0];
    }

    // ═══════════════════════════════════════════════════════════════
    //  12. PROFILE COMPLETION — Percentage + Missing Fields
    // ═══════════════════════════════════════════════════════════════

    public function getProfileCompletion(int $patientId): array
    {
        $sql = "
            SELECT u.name, u.phone,
                   mr.blood_type, mr.date_of_birth, mr.gender,
                   mr.allergies, mr.chronic_diseases,
                   mr.emergency_contact_name, mr.emergency_contact_phone,
                   mr.governorate, mr.city, mr.insurance_provider, mr.insurance_number
            FROM users u
            LEFT JOIN medical_records mr ON u.id = mr.patient_id
            WHERE u.id = ? LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $data = $stmt->fetch();

        if (!$data) {
            return ['percentage' => 0, 'missing_fields' => [
                ['field' => 'insurance', 'label' => 'Insurance Information'],
                ['field' => 'emergency_contact', 'label' => 'Emergency Contact'],
                ['field' => 'blood_type', 'label' => 'Blood Type'],
                ['field' => 'date_of_birth', 'label' => 'Date of Birth'],
                ['field' => 'allergies', 'label' => 'Allergies Information'],
                ['field' => 'address', 'label' => 'Address'],
            ], 'completed_fields' => [], 'total_checks' => 9, 'completed' => 0];
        }

        $checks = [
            'blood_type'         => !empty($data['blood_type']),
            'date_of_birth'      => !empty($data['date_of_birth']),
            'gender'             => !empty($data['gender']),
            'phone'              => !empty($data['phone']),
            'allergies'          => !empty($data['allergies']),
            'chronic_diseases'   => !empty($data['chronic_diseases']),
            'emergency_contact'  => !empty($data['emergency_contact_name']) && !empty($data['emergency_contact_phone']),
            'insurance'          => !empty($data['insurance_provider']) && !empty($data['insurance_number']),
            'address'            => !empty($data['governorate']) && !empty($data['city']),
        ];

        $fieldLabels = [
            'blood_type'        => 'Blood Type',
            'date_of_birth'     => 'Date of Birth',
            'gender'            => 'Gender',
            'phone'             => 'Phone Number',
            'allergies'         => 'Allergies Information',
            'chronic_diseases'  => 'Chronic Diseases',
            'emergency_contact' => 'Emergency Contact',
            'insurance'         => 'Insurance Information',
            'address'           => 'Address (Governorate & City)',
        ];

        $completed = [];
        $missing = [];

        foreach ($checks as $field => $isComplete) {
            if ($isComplete) {
                $completed[] = ['field' => $field, 'label' => $fieldLabels[$field]];
            } else {
                $missing[] = ['field' => $field, 'label' => $fieldLabels[$field]];
            }
        }

        $totalChecks = count($checks);
        $completedCount = count($completed);
        $percentage = $totalChecks > 0 ? round(($completedCount / $totalChecks) * 100) : 0;

        return [
            'percentage'      => $percentage,
            'missing_fields'  => $missing,
            'completed_fields' => $completed,
            'total_checks'    => $totalChecks,
            'completed'       => $completedCount,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  13. GLOBAL SEARCH — Search Everywhere
    // ═══════════════════════════════════════════════════════════════

    public function searchAll(int $patientId, string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (empty($query) || strlen($query) < 2) {
            return ['appointments' => [], 'doctors' => [], 'prescriptions' => [], 'history' => [], 'total' => 0];
        }

        $searchTerm = '%' . $query . '%';
        $results = [];
        $total = 0;

        $apptSql = "SELECT id, doctor, department, date, appointment_time_range, status, 'appointment' AS result_type
                    FROM appointments WHERE user_id = ? AND (doctor LIKE ? OR department LIKE ? OR notes LIKE ? OR patient_name LIKE ?)
                    ORDER BY date DESC LIMIT ?";
        $stmt = $this->db->prepare($apptSql);
        $stmt->execute([$patientId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
        $results['appointments'] = $stmt->fetchAll();
        $total += count($results['appointments']);

        $docSql = "SELECT DISTINCT a.doctor_id, u.name AS doctor_name, d.specialty, d.rating, 'doctor' AS result_type
                   FROM appointments a JOIN users u ON a.doctor_id = u.id JOIN doctors d ON a.doctor_id = d.user_id
                   WHERE a.user_id = ? AND u.name LIKE ? ORDER BY u.name ASC LIMIT ?";
        $stmt = $this->db->prepare($docSql);
        $stmt->execute([$patientId, $searchTerm, $limit]);
        $results['doctors'] = $stmt->fetchAll();
        $total += count($results['doctors']);

        $rxSql = "SELECT p.id, u.name AS doctor_name, a.date AS appt_date, a.department, p.status, p.created_at, 'prescription' AS result_type
                  FROM prescriptions p JOIN users u ON p.doctor_id = u.id JOIN appointments a ON p.appointment_id = a.id
                  WHERE p.patient_id = ? AND (u.name LIKE ? OR a.department LIKE ? OR p.notes LIKE ?)
                  ORDER BY p.created_at DESC LIMIT ?";
        $stmt = $this->db->prepare($rxSql);
        $stmt->execute([$patientId, $searchTerm, $searchTerm, $searchTerm, $limit]);
        $results['prescriptions'] = $stmt->fetchAll();
        $total += count($results['prescriptions']);

        $histSql = "SELECT vn.id, a.date, a.doctor, a.department, vn.diagnosis, vn.symptoms, vn.treatment, 'history' AS result_type
                    FROM visit_notes vn JOIN appointments a ON vn.appointment_id = a.id
                    WHERE vn.patient_id = ? AND (vn.diagnosis LIKE ? OR vn.symptoms LIKE ? OR vn.treatment LIKE ? OR vn.doctor_notes LIKE ?)
                    ORDER BY a.date DESC LIMIT ?";
        $stmt = $this->db->prepare($histSql);
        $stmt->execute([$patientId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
        $results['history'] = $stmt->fetchAll();
        $total += count($results['history']);

        return ['appointments' => $results['appointments'] ?? [], 'doctors' => $results['doctors'] ?? [],
                'prescriptions' => $results['prescriptions'] ?? [], 'history' => $results['history'] ?? [], 'total' => $total];
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function getHospitalStatus(): array
    {
        if ($this->hospitalCache !== null) return $this->hospitalCache;

        try {
            $stmt = $this->db->query("SELECT hospital_name, appointment_open_time, appointment_close_time FROM hospital_settings WHERE id = 1 LIMIT 1");
            $h = $stmt->fetch();
        } catch (\Exception $e) {
            $h = null;
        }

        $name = $h['hospital_name'] ?? 'HealthBridge Hospital';
        $openTime = $h['appointment_open_time'] ?? '08:00';
        $closeTime = $h['appointment_close_time'] ?? '22:00';

        $currentMinutes = (int)date('G') * 60 + (int)date('i');
        $openMinutes = $this->timeToMinutes($openTime);
        $closeMinutes = $this->timeToMinutes($closeTime);
        $isOpen = $currentMinutes >= $openMinutes && $currentMinutes < $closeMinutes;

        if ((int)date('N') >= 6) $isOpen = false;

        $this->hospitalCache = ['name' => $name, 'is_open' => $isOpen, 'open_time' => $openTime, 'close_time' => $closeTime];
        return $this->hospitalCache;
    }

    private function computeCountdown(string $date, ?string $time = null): ?array
    {
        try {
            $datetimeStr = $date;
            if ($time) {
                $normalized = $time;
                if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $m)) {
                    $h = (int)$m[1];
                    if (strtoupper($m[3]) === 'PM' && $h !== 12) $h += 12;
                    if (strtoupper($m[3]) === 'AM' && $h === 12) $h = 0;
                    $normalized = sprintf('%02d:%02d', $h, (int)$m[2]);
                }
                $datetimeStr .= ' ' . $normalized;
            }
            $target = new DateTime($datetimeStr);
            $now = new DateTime();
            $diff = $now->diff($target);
            if ($diff->invert) return null;

            if ($diff->days === 0 && $diff->h === 0 && $diff->i < 60)
                return ['label' => 'In ' . ($diff->i > 0 ? $diff->i . ' min' : 'moments'), 'days' => 0, 'hours' => 0, 'minutes' => $diff->i, 'is_today' => true];
            if ($diff->days === 0)
                return ['label' => 'Today', 'days' => 0, 'hours' => $diff->h, 'minutes' => $diff->i, 'is_today' => true];
            if ($diff->days === 1)
                return ['label' => 'Tomorrow', 'days' => 1, 'hours' => $diff->h, 'minutes' => $diff->i, 'is_today' => false];
            if ($diff->days <= 7)
                return ['label' => "In {$diff->days} days ({$target->format('l')})", 'days' => $diff->days, 'hours' => $diff->h, 'minutes' => $diff->i, 'is_today' => false];
            return ['label' => $target->format('M j, Y'), 'days' => $diff->days, 'hours' => $diff->h, 'minutes' => $diff->i, 'is_today' => false];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
    }

    private function relativeTime(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if (!$timestamp) return 'Unknown';
        $diff = time() - $timestamp;
        if ($diff < 60) return $diff <= 5 ? 'Just now' : $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j', $timestamp);
    }

    private function parseListField(?string $value): array
    {
        if (empty($value)) return [];
        $items = preg_split('/[,\n]+/', $value);
        return array_map('trim', array_filter($items, fn($i) => !empty(trim($i))));
    }

    private function emptyOverview(): array
    {
        $hospital = $this->getHospitalStatus();
        return ['greeting' => 'Welcome', 'first_name' => 'Patient', 'full_name' => 'Patient',
            'patient_number' => '-', 'email' => '', 'phone' => '', 'member_since' => null,
            'hospital' => $hospital, 'current_time' => date('l, F j, Y · g:i A'),
            'current_date' => date('Y-m-d'), 'next_appointment' => null, 'primary_doctor' => null,
            'unread_notifications' => 0];
    }

    private function emptySnapshot(): array
    {
        return ['total_appointments' => 0, 'upcoming_appointments' => 0, 'completed_appointments' => 0,
            'active_prescriptions' => 0, 'total_prescriptions' => 0, 'has_medical_record' => false,
            'total_notifications' => 0, 'unread_notifications' => 0, 'doctors_seen' => 0,
            'last_visit' => null, 'cancelled_appointments' => 0, 'missed_appointments' => 0,
            'total_prescriptions_issued' => 0, 'profile_completion' => 0];
    }

    private function emptyProfile(): array
    {
        return ['id' => 0, 'name' => 'Patient', 'first_name' => null, 'last_name' => null,
            'email' => '', 'phone' => '', 'patient_number' => '-', 'national_id' => null,
            'registered_at' => null, 'date_of_birth' => null, 'age' => null, 'gender' => null,
            'blood_type' => null, 'height_cm' => null, 'weight_kg' => null, 'bmi' => null,
            'allergies' => null, 'allergies_list' => [], 'chronic_diseases' => null,
            'chronic_diseases_list' => [], 'current_medications' => null, 'previous_surgeries' => null,
            'family_history' => null, 'emergency_contact' => ['name' => null, 'relationship' => null, 'phone' => null],
            'location' => ['governorate' => null, 'city' => null, 'address' => null],
            'insurance' => ['provider' => null, 'number' => null], 'medical_notes' => null];
    }
}