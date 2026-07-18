<?php
/**
 * HealthBridge — Visit Draft Service (Phase 5.4)
 * Manages autosave drafts for visit notes in database.
 * Replaces localStorage-based draft system.
 */

class VisitDraftService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Save or update a draft for an appointment.
     */
    public function save(int $appointmentId, int $doctorId, array $data): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO visit_drafts 
             (appointment_id, doctor_id, diagnosis, symptoms, treatment, doctor_notes, follow_up_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             diagnosis = VALUES(diagnosis),
             symptoms = VALUES(symptoms),
             treatment = VALUES(treatment),
             doctor_notes = VALUES(doctor_notes),
             follow_up_instructions = VALUES(follow_up_instructions),
             updated_at = NOW()"
        );
        
        return $stmt->execute([
            $appointmentId,
            $doctorId,
            $data['diagnosis'] ?? null,
            $data['symptoms'] ?? null,
            $data['treatment'] ?? null,
            $data['doctor_notes'] ?? null,
            $data['follow_up_instructions'] ?? null,
        ]);
    }

    /**
     * Get draft for an appointment.
     */
    public function get(int $appointmentId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM visit_drafts WHERE appointment_id = ? LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete draft for an appointment.
     */
    public function delete(int $appointmentId): bool {
        $stmt = $this->db->prepare(
            "DELETE FROM visit_drafts WHERE appointment_id = ?"
        );
        return $stmt->execute([$appointmentId]);
    }

    /**
     * Clean up old drafts (older than 7 days).
     */
    public function cleanupOld(): int {
        $stmt = $this->db->prepare(
            "DELETE FROM visit_drafts WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
