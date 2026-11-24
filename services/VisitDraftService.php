<?php
declare(strict_types=1);

require_once __DIR__ . '/Repositories/ClinicalEncounterRepository.php';

/**
 * Visit draft facade.
 */
class VisitDraftService
{
    private PDO $db;
    private ClinicalEncounterRepository $encounters;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->encounters = new ClinicalEncounterRepository($db);
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
