<?php
declare(strict_types=1);

require_once __DIR__ . '/../NotificationService.php';

/**
 * Schema-aware self-deactivation workflow for patients, doctors and admins.
 *
 * Locks and mutations are kept within one transaction. The caller supplies
 * the server-session identity and the password confirmation; no user ID or
 * role is trusted from request JSON.
 */
final class AccountLifecycleRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('AccountLifecycleRepository schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }

    public function deactivateSelf(int $userId, string $role, string $password): array
    {
        if ($userId <= 0 || !in_array($role, ['patient','doctor','admin'], true) || $password === '') {
            throw new InvalidArgumentException('A valid authenticated account and password confirmation are required.');
        }

        return $this->withTransaction(function () use ($userId, $role, $password): array {
            // Preserve the existing deterministic active-admin lock ordering.
            $activeAdminCount = null;
            if ($role === 'admin') {
                $adminLocks = $this->db->query(
                    "SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id FOR UPDATE"
                )->fetchAll(PDO::FETCH_COLUMN);
                $activeAdminCount = count($adminLocks);
            }

            $passwordColumn = $this->schema === self::SCHEMA_V1 ? 'password' : 'password_hash';
            $stmt = $this->db->prepare(
                "SELECT id,role,is_active,{$passwordColumn} AS password_hash
                 FROM users WHERE id=? AND role=? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$userId,$role]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($password,(string)$account['password_hash'])) {
                throw new DomainException('Password is incorrect. Account remains active.');
            }
            if ((int)$account['is_active'] !== 1) {
                throw new DomainException('Your account is already deactivated.');
            }
            if ($role === 'admin' && (int)$activeAdminCount <= 1) {
                throw new DomainException('The last active administrator cannot deactivate their own account. Assign another active administrator first.');
            }

            if ($role === 'doctor') {
                $this->assertNoUnfinishedDoctorWork($userId);
            }

            $appointments = match ($role) {
                'patient' => $this->findCancellableAppointments($userId,'patient'),
                'doctor' => $this->findCancellableAppointments($userId,'doctor'),
                default => [],
            };

            foreach ($appointments as $appointment) {
                $appointmentId = (int)$appointment['id'];
                $this->cancelAppointment($appointmentId,$userId,$role,$appointment);
                if ($role === 'doctor' && !empty($appointment['patient_user_id'])) {
                    $this->notify(
                        (int)$appointment['patient_user_id'],
                        NotificationService::TYPE_APPOINTMENT_CANCELLED,
                        'Appointment Cancelled',
                        'Your appointment was cancelled because the doctor deactivated their account.',
                        'appointment',
                        $appointmentId
                    );
                }
            }

            $deactivate = $this->db->prepare(
                'UPDATE users SET is_active=0,session_version=session_version+1,updated_at=NOW()
                 WHERE id=? AND role=? AND is_active=1'
            );
            $deactivate->execute([$userId,$role]);
            if ($deactivate->rowCount() !== 1) {
                throw new RuntimeException('Account status changed during deactivation.');
            }

            $this->writeAudit(
                $userId,
                $role,
                'deactivate',
                'user',
                $userId,
                ['is_active'=>1],
                ['is_active'=>0],
                'User self-deactivated their account; sign-in disabled; ' . count($appointments)
                    . ' not-started appointment(s) cancelled; clinical history retained.',
                $role === 'patient' ? $userId : null,
                $role === 'doctor' ? $userId : null
            );

            return [
                'user_id'=>$userId,
                'role'=>$role,
                'message'=>'account_deactivated',
                'cancelled_appointments'=>count($appointments),
            ];
        });
    }

    private function assertNoUnfinishedDoctorWork(int $doctorUserId): void
    {
        if ($this->schema === self::SCHEMA_V1) {
            $visit = $this->db->prepare(
                "SELECT COUNT(*) FROM appointments a
                 INNER JOIN visit_workflow w ON w.appointment_id=a.id
                 WHERE a.doctor_id=? AND a.status='Confirmed'
                   AND w.status IN ('In Progress','Ready to Complete')"
            );
            $visit->execute([$doctorUserId]);
            $prescriptions = $this->db->prepare(
                "SELECT COUNT(*) FROM prescriptions WHERE doctor_id=? AND status='Active'"
            );
            $prescriptions->execute([$doctorUserId]);
            $drafts = $this->db->prepare('SELECT COUNT(*) FROM visit_drafts WHERE doctor_id=?');
            $drafts->execute([$doctorUserId]);
        } else {
            $visit = $this->db->prepare(
                "SELECT COUNT(*) FROM appointments a
                 INNER JOIN clinical_encounters ce ON ce.appointment_id=a.id
                 WHERE a.doctor_user_id=? AND a.booking_status='confirmed'
                   AND ce.workflow_status IN ('in_progress','ready_to_complete')"
            );
            $visit->execute([$doctorUserId]);
            $prescriptions = $this->db->prepare(
                "SELECT COUNT(*) FROM prescriptions WHERE doctor_user_id=? AND status='active'"
            );
            $prescriptions->execute([$doctorUserId]);
            $drafts = $this->db->prepare('SELECT COUNT(*) FROM encounter_drafts WHERE doctor_user_id=?');
            $drafts->execute([$doctorUserId]);
        }

        if ((int)$visit->fetchColumn()>0 || (int)$prescriptions->fetchColumn()>0 || (int)$drafts->fetchColumn()>0) {
            throw new DomainException(
                'You still have an active visit, unfinished visit draft, or active prescription. Complete or transfer that clinical work before deactivating your account.'
            );
        }
    }

    private function findCancellableAppointments(int $userId,string $role): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            $ownerColumn = $role === 'patient' ? 'a.user_id' : 'a.doctor_id';
            $sql = "SELECT a.id,a.user_id AS patient_user_id,a.date,a.time
                    FROM appointments a
                    LEFT JOIN visit_workflow w ON w.appointment_id=a.id
                    WHERE {$ownerColumn}=?
                      AND a.status IN ('Pending','Confirmed','Reschedule Requested')
                      AND (w.status IS NULL OR w.status='Waiting')
                      AND NOT EXISTS (SELECT 1 FROM visit_drafts vd WHERE vd.appointment_id=a.id)
                      AND NOT EXISTS (SELECT 1 FROM visit_notes vn WHERE vn.appointment_id=a.id)
                      AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id=a.id)
                    FOR UPDATE";
        } else {
            $ownerColumn = $role === 'patient' ? 'a.patient_user_id' : 'a.doctor_user_id';
            $sql = "SELECT a.id,a.patient_user_id,a.starts_at
                    FROM appointments a
                    LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
                    WHERE {$ownerColumn}=?
                      AND a.booking_status IN ('pending','confirmed')
                      AND (
                          ce.id IS NULL OR (
                              ce.workflow_status IN ('uninitialized','waiting')
                              AND ce.diagnosis IS NULL AND ce.symptoms IS NULL
                              AND ce.treatment IS NULL AND ce.doctor_notes IS NULL
                              AND ce.follow_up_instructions IS NULL
                          )
                      )
                      AND NOT EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.appointment_id=a.id)
                      AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id=a.id)
                    FOR UPDATE";
        }
        $stmt=$this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cancelAppointment(int $appointmentId,int $actorUserId,string $actorRole,array $appointment): void
    {
        if ($this->schema === self::SCHEMA_V1) {
            $stmt=$this->db->prepare(
                "UPDATE appointments SET status='Cancelled'
                 WHERE id=? AND status IN ('Pending','Confirmed','Reschedule Requested')"
            );
            $stmt->execute([$appointmentId]);
            if ($stmt->rowCount()!==1) {
                throw new RuntimeException('An appointment changed during account deactivation.');
            }
            return;
        }

        $current=$this->db->prepare('SELECT booking_status FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
        $current->execute([$appointmentId]);
        $fromStatus=(string)$current->fetchColumn();
        if (!in_array($fromStatus,['pending','confirmed'],true)) {
            throw new RuntimeException('An appointment changed during account deactivation.');
        }
        $cancel=$this->db->prepare(
            "UPDATE appointments SET booking_status='cancelled'
             WHERE id=? AND booking_status IN ('pending','confirmed')"
        );
        $cancel->execute([$appointmentId]);
        if ($cancel->rowCount()!==1) {
            throw new RuntimeException('An appointment changed during account deactivation.');
        }
        $this->db->prepare(
            "UPDATE appointment_reschedule_requests
             SET request_status='cancelled',responded_by_user_id=?,responded_at=NOW()
             WHERE appointment_id=? AND request_status IN ('requested','suggested')"
        )->execute([$actorUserId,$appointmentId]);

        $details=['reason'=>$actorRole.'_self_deactivated'];
        $this->writeAppointmentEvent(
            $appointmentId,
            $actorUserId,
            $actorRole,
            $actorRole.'_self_deactivated',
            $fromStatus,
            $details
        );
    }

    private function writeAudit(
        int $actorUserId,string $actorRole,string $action,string $entityType,int $entityId,
        mixed $oldValue,mixed $newValue,string $description,?int $patientId,?int $doctorId
    ): void {
        $oldJson=json_encode($oldValue,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $newJson=json_encode($newValue,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if ($this->schema===self::SCHEMA_V1) {
            $stmt=$this->db->prepare(
                'INSERT INTO admin_audit
                 (actor_id,actor_role,action,entity_type,entity_id,patient_id,doctor_id,
                  old_value,new_value,description,ip_address,user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
        } else {
            $stmt=$this->db->prepare(
                'INSERT INTO audit_events
                 (actor_user_id,actor_role_snapshot,action_code,entity_type,entity_id,
                  patient_user_id,doctor_user_id,old_value,new_value,description,ip_address,user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
        }
        $stmt->execute([
            $actorUserId,$actorRole,$action,$entityType,(string)$entityId,
            $patientId,$doctorId,$oldJson,$newJson,$description,
            $_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null
        ]);
    }

    private function writeAppointmentEvent(
        int $appointmentId,int $actorUserId,string $actorRole,string $eventType,string $fromStatus,array $details
    ): void {
        if ($this->schema!==self::SCHEMA_V2) { return; }
        $stmt=$this->db->prepare(
            "INSERT INTO appointment_events
             (appointment_id,actor_user_id,actor_role_snapshot,event_type,from_status,to_status,event_details,created_at)
             VALUES (?,?,?,?,?,'cancelled',?,NOW())"
        );
        $stmt->execute([
            $appointmentId,$actorUserId,$actorRole,$eventType,$fromStatus,
            json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function notify(int $userId,string $type,string $title,string $message,string $refType,int $refId): void
    {
        (new NotificationService($this->db))->create($userId,$type,$title,$message,$refType,$refId);
    }

    private function withTransaction(callable $operation): mixed
    {
        $owns=!$this->db->inTransaction();
        if ($owns) { $this->db->beginTransaction(); }
        try {
            $result=$operation();
            if ($owns) { $this->db->commit(); }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
