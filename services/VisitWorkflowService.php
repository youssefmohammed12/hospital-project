<?php
declare(strict_types=1);

require_once __DIR__ . '/Repositories/ClinicalEncounterRepository.php';

/**
 * Visit workflow facade.
 *
 * Existing callers remain on the v1 schema through the one-argument constructor.
 * Explicit v2 mode is used only by isolated compatibility tests/future adapters.
 */
class VisitWorkflowService
{
    public const STATUS_WAITING = ClinicalEncounterRepository::STATUS_WAITING;
    public const STATUS_IN_PROGRESS = ClinicalEncounterRepository::STATUS_IN_PROGRESS;
    public const STATUS_READY_TO_COMPLETE = ClinicalEncounterRepository::STATUS_READY_TO_COMPLETE;
    public const STATUS_COMPLETED = ClinicalEncounterRepository::STATUS_COMPLETED;

    private PDO $db;
    private string $schema;
    private ClinicalEncounterRepository $encounters;

    public function __construct(PDO $db, string $schema = ClinicalEncounterRepository::SCHEMA_V1)
    {
        if (!in_array($schema, [ClinicalEncounterRepository::SCHEMA_V1, ClinicalEncounterRepository::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('VisitWorkflowService schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
        $this->encounters = new ClinicalEncounterRepository($db, $schema);
    }

    public function get(int $appointmentId): ?array
    {
        return $this->encounters->getWorkflow($appointmentId);
    }

    public function getOrCreateWorkflow(int $appointmentId): array
    {
        return $this->encounters->getOrCreateWorkflow($appointmentId);
    }

    public function create(int $appointmentId, string $status = self::STATUS_WAITING): bool
    {
        return $this->encounters->createWorkflow($appointmentId, $status);
    }

    public function transition(int $appointmentId, string $newStatus): bool
    {
        return $this->encounters->transitionWorkflow($appointmentId, $newStatus);
    }

    public function getForAppointments(array $appointmentIds): array
    {
        return $this->encounters->getWorkflowsForAppointments($appointmentIds);
    }

    public function migrateExisting(): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $sql = $this->schema === ClinicalEncounterRepository::SCHEMA_V1
            ? "SELECT id,status FROM appointments ORDER BY id"
            : "SELECT id,booking_status AS status FROM appointments ORDER BY id";
        $appointments = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($appointments as $appointment) {
            $status = $this->schema === ClinicalEncounterRepository::SCHEMA_V1
                ? ((string)$appointment['status'] === 'Confirmed')
                : ((string)$appointment['status'] === 'confirmed');
            if (!$status) {
                $stats['skipped']++;
                continue;
            }

            try {
                $existing = $this->get((int)$appointment['id']);
                $this->getOrCreateWorkflow((int)$appointment['id']);
                if ($existing === null) {
                    $stats['created']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log('Workflow migration error for appointment ' . $appointment['id'] . ': ' . get_class($e));
            }
        }

        return $stats;
    }

    public function getNote(int $appointmentId): ?array
    {
        return $this->encounters->getNote($appointmentId);
    }

    public function getNotesForPatient(int $patientUserId): array
    {
        return $this->encounters->getNotesForPatient($patientUserId);
    }

    public function saveNote(int $appointmentId, int $patientUserId, int $doctorUserId, array $data): bool
    {
        return $this->encounters->saveNote($appointmentId, $patientUserId, $doctorUserId, $data);
    }
}
