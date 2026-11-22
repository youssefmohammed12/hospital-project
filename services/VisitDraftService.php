<?php
declare(strict_types=1);

require_once __DIR__ . '/Repositories/ClinicalEncounterRepository.php';

/**
 * Visit draft facade. Defaults to v1 for every existing endpoint.
 */
class VisitDraftService
{
    private PDO $db;
    private string $schema;
    private ClinicalEncounterRepository $encounters;

    public function __construct(PDO $db, string $schema = ClinicalEncounterRepository::SCHEMA_V1)
    {
        if (!in_array($schema, [ClinicalEncounterRepository::SCHEMA_V1, ClinicalEncounterRepository::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('VisitDraftService schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
        $this->encounters = new ClinicalEncounterRepository($db, $schema);
    }

    public function save(int $appointmentId, int $doctorId, array $data): bool
    {
        return $this->encounters->saveDraft($appointmentId, $doctorId, $data);
    }

    public function get(int $appointmentId): ?array
    {
        return $this->encounters->getDraft($appointmentId);
    }

    public function delete(int $appointmentId): bool
    {
        return $this->encounters->deleteDraft($appointmentId);
    }

    public function cleanupOld(): int
    {
        return $this->encounters->cleanupOldDrafts(7);
    }
}
