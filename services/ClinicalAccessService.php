<?php
declare(strict_types=1);

/**
 * Clinical access policy.
 *
 * Confirmed appointments permit pre-visit preparation. Cancelled appointments
 * retain access only when there is evidence of actual care: workflow reached
 * an active/completed state, a clinical note exists, or a prescription exists.
 */
class ClinicalAccessService
{
    private PDO $db;
    private string $schema;

    private const CARE_WORKFLOW_STATES_V1 = [
        'In Progress',
        'Ready to Complete',
        'Completed',
    ];

    private const CARE_WORKFLOW_STATES_V2 = [
        'in_progress',
        'ready_to_complete',
        'completed',
    ];

    public function __construct(PDO $db, string $schema = 'v1')
    {
        if (!in_array($schema, ['v1','v2'], true)) {
            throw new InvalidArgumentException('ClinicalAccessService schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }

    /**
     * Lock account rows before appointment/clinical rows.
     * Patient is_active controls portal login, not continuity of care.
     */
    public function lockDoctorAndPatient(int $doctorId, ?int $patientId = null): bool
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Clinical account locks require an active transaction.');
        }
        if ($doctorId <= 0) {
            return false;
        }

        $doctorStmt = $this->db->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'doctor' AND is_active = 1 LIMIT 1 FOR UPDATE"
        );
        $doctorStmt->execute([$doctorId]);
        if (!$doctorStmt->fetchColumn()) {
            return false;
        }

        if ($patientId !== null) {
            if ($patientId <= 0) {
                return false;
            }
            $patientStmt = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1 FOR UPDATE"
            );
            $patientStmt->execute([$patientId]);
            if (!$patientStmt->fetchColumn()) {
                return false;
            }
        }

        return true;
    }

    public function doctorCanAccessPatient(int $doctorId, int $patientId, bool $forUpdate = false): bool
    {
        if ($doctorId <= 0 || $patientId <= 0) {
            return false;
        }
        if ($forUpdate && !$this->db->inTransaction()) {
            throw new RuntimeException('Clinical access row locks require an active transaction.');
        }

        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        if ($this->schema === 'v1') {
            $workflowStates = implode(',', array_map(
                static fn(string $status): string => "'" . str_replace("'", "''", $status) . "'",
                self::CARE_WORKFLOW_STATES_V1
            ));
            $sql = "SELECT a.id
                    FROM appointments a
                    INNER JOIN users patient
                      ON patient.id=a.user_id AND patient.role='patient'
                    WHERE a.doctor_id=? AND a.user_id=?
                      AND (
                        a.status='Confirmed'
                        OR EXISTS (
                            SELECT 1 FROM visit_workflow vw
                            WHERE vw.appointment_id=a.id AND vw.status IN ({$workflowStates})
                        )
                        OR EXISTS (
                            SELECT 1 FROM visit_notes vn
                            WHERE vn.appointment_id=a.id
                              AND vn.patient_id=a.user_id AND vn.doctor_id=a.doctor_id
                        )
                        OR EXISTS (
                            SELECT 1 FROM prescriptions p
                            WHERE p.appointment_id=a.id
                              AND p.patient_id=a.user_id AND p.doctor_id=a.doctor_id
                        )
                      )
                    LIMIT 1{$lockClause}";
        } else {
            $workflowStates = implode(',', array_map(
                static fn(string $status): string => "'" . str_replace("'", "''", $status) . "'",
                self::CARE_WORKFLOW_STATES_V2
            ));
            $sql = "SELECT a.id
                    FROM appointments a
                    INNER JOIN users patient
                      ON patient.id=a.patient_user_id AND patient.role='patient'
                    WHERE a.doctor_user_id=? AND a.patient_user_id=?
                      AND (
                        (
                          a.booking_status='confirmed'
                          AND NOT EXISTS (
                            SELECT 1 FROM appointment_reschedule_requests rr
                            WHERE rr.appointment_id=a.id
                              AND rr.request_status IN ('requested','suggested')
                          )
                        )
                        OR EXISTS (
                            SELECT 1 FROM clinical_encounters ce
                            WHERE ce.appointment_id=a.id
                              AND ce.workflow_status IN ({$workflowStates})
                        )
                        OR EXISTS (
                            SELECT 1 FROM clinical_encounters ce
                            WHERE ce.appointment_id=a.id
                              AND (
                                ce.diagnosis IS NOT NULL OR ce.symptoms IS NOT NULL
                                OR ce.treatment IS NOT NULL OR ce.doctor_notes IS NOT NULL
                                OR ce.follow_up_instructions IS NOT NULL
                              )
                        )
                        OR EXISTS (
                            SELECT 1 FROM prescriptions p
                            WHERE p.appointment_id=a.id
                              AND p.patient_user_id=a.patient_user_id
                              AND p.doctor_user_id=a.doctor_user_id
                        )
                      )
                    LIMIT 1{$lockClause}";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorId,$patientId]);
        return $stmt->fetchColumn() !== false;
    }
}
