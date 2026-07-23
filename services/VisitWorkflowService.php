<?php
/**
 * HealthBridge — Visit Workflow Service (Phase 5.3.2)
 * Dedicated workflow state per appointment.
 * Does NOT overload appointments.status.
 *
 * Workflow values: Waiting, In Progress, Ready to Complete, Completed
 * Appointment status: Pending, Confirmed, Cancelled (unchanged)
 */

class VisitWorkflowService {
    private PDO $db;

    const STATUS_WAITING          = 'Waiting';
    const STATUS_IN_PROGRESS      = 'In Progress';
    const STATUS_READY_TO_COMPLETE = 'Ready to Complete';
    const STATUS_COMPLETED        = 'Completed';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get workflow for an appointment.
     * Returns null if no workflow record exists.
     * Does NOT auto-create - use getOrCreateWorkflow() for that.
     */
    public function get(int $appointmentId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM visit_workflow WHERE appointment_id = ? LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get or create workflow for an appointment.
     * Always returns a workflow record.
     * Auto-creates if missing with appropriate status based on appointment state.
     * Phase 5.4.1: Self-healing pattern - never returns null.
     */
    public function getOrCreateWorkflow(int $appointmentId): array {
        $workflow = $this->get($appointmentId);
        
        if ($workflow) {
            return $workflow;
        }

        // Workflow doesn't exist - determine appropriate status
        $apptStmt = $this->db->prepare(
            "SELECT id, date, status FROM appointments WHERE id = ? LIMIT 1"
        );
        $apptStmt->execute([$appointmentId]);
        $appt = $apptStmt->fetch();

        if (!$appt) {
            // Appointment doesn't exist - create with Waiting status
            $this->create($appointmentId, self::STATUS_WAITING);
        } elseif ($appt['status'] === 'Confirmed') {
            // Confirmed appointment - create workflow with Waiting status.
            // Do NOT auto-complete past appointments. Only the doctor's explicit
            // action through the visit workflow should mark an appointment as Completed.
            $this->create($appointmentId, self::STATUS_WAITING);
        } else {
            // Not confirmed - create with Waiting status
            $this->create($appointmentId, self::STATUS_WAITING);
        }

        // Return the newly created workflow
        return $this->get($appointmentId);
    }

    /**
     * Create a workflow record for an appointment.
     * Called when an appointment is confirmed.
     */
    public function create(int $appointmentId, string $status = self::STATUS_WAITING): bool {
        // Check if already exists
        $existing = $this->get($appointmentId);
        if ($existing) return true;

        $stmt = $this->db->prepare(
            "INSERT INTO visit_workflow (appointment_id, status) VALUES (?, ?)"
        );
        $stmt->execute([$appointmentId, $status]);
        return true;
    }

    /**
     * Transition workflow to a new status.
     * Automatically sets started_at when transitioning to In Progress.
     * Automatically sets completed_at when transitioning to Completed.
     */
    public function transition(int $appointmentId, string $newStatus): bool {
        $workflow = $this->get($appointmentId);
        if (!$workflow) {
            // Create if doesn't exist
            $this->create($appointmentId, $newStatus);
            return true;
        }

        $updates = ['status = ?'];
        $params = [$newStatus];

        // Set started_at when moving to In Progress
        if ($newStatus === self::STATUS_IN_PROGRESS && empty($workflow['started_at'])) {
            $updates[] = 'started_at = NOW()';
        }

        // Set completed_at when moving to Completed
        if ($newStatus === self::STATUS_COMPLETED && empty($workflow['completed_at'])) {
            $updates[] = 'completed_at = NOW()';
        }

        $params[] = $appointmentId;
        $stmt = $this->db->prepare(
            "UPDATE visit_workflow SET " . implode(', ', $updates) . " WHERE appointment_id = ?"
        );
        $stmt->execute($params);
        return true;
    }

    /**
     * Get workflow for multiple appointments.
     * Returns map of appointment_id => workflow.
     */
    public function getForAppointments(array $appointmentIds): array {
        if (empty($appointmentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM visit_workflow WHERE appointment_id IN ($placeholders)"
        );
        $stmt->execute($appointmentIds);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['appointment_id']] = $row;
        }
        return $map;
    }

    /**
     * Migrate existing appointments to have workflow records.
     * Phase 5.4.1: Idempotent - uses getOrCreateWorkflow for all confirmed appointments.
     * Past confirmed -> Completed
     * Future confirmed -> Waiting
     * Pending/Cancelled -> no workflow
     */
    public function migrateExisting(): array {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $stmt = $this->db->query(
            "SELECT id, date, status FROM appointments ORDER BY id"
        );
        $appointments = $stmt->fetchAll();

        foreach ($appointments as $appt) {
            try {
                $status = $appt['status'];
                if ($status === 'Confirmed') {
                    // Phase 5.4.1: Use getOrCreateWorkflow - it handles the logic
                    $workflow = $this->getOrCreateWorkflow((int)$appt['id']);
                    if ($workflow) {
                        $stats['created']++;
                    }
                } else {
                    $stats['skipped']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                error_log('Migration error for appointment ' . $appt['id'] . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }
}