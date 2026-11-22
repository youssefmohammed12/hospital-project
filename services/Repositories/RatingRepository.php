<?php
declare(strict_types=1);

require_once __DIR__ . '/../ClinicalAccessService.php';
require_once __DIR__ . '/../NotificationService.php';

/** Schema-aware rating creation for completed patient appointments. */
final class RatingRepository
{
    public const SCHEMA_V1='v1';
    public const SCHEMA_V2='v2';

    private PDO $db;
    private string $schema;
    private ?bool $v1HasHiddenColumn = null;

    public function __construct(PDO $db,string $schema)
    {
        if(!in_array($schema,[self::SCHEMA_V1,self::SCHEMA_V2],true)){
            throw new InvalidArgumentException('RatingRepository schema must be explicitly v1 or v2.');
        }
        $this->db=$db;
        $this->schema=$schema;
    }

    private function supportsLegacyVisibilityColumn(): bool
    {
        if ($this->schema !== self::SCHEMA_V1) {
            return false;
        }
        if ($this->v1HasHiddenColumn === null) {
            $this->v1HasHiddenColumn = (int)$this->db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_hidden'"
            )->fetchColumn() === 1;
        }
        return $this->v1HasHiddenColumn;
    }

    /**
     * Set whether a rating participates in public doctor-facing aggregates.
     * The repository locks both the acting admin and rating and writes one audit
     * record only when the requested visibility differs from the current state.
     */
    public function setPublicVisibility(int $ratingId, bool $isPublic, int $adminUserId): array
    {
        if ($ratingId <= 0 || $adminUserId <= 0) {
            throw new InvalidArgumentException('Rating and administrator IDs must be positive.');
        }

        return $this->withTransaction(function () use ($ratingId, $isPublic, $adminUserId): array {
            if ($this->schema === self::SCHEMA_V1 && !$this->supportsLegacyVisibilityColumn()) {
                throw new DomainException('This V1 database does not include ratings.is_hidden.');
            }

            $actor = $this->db->prepare(
                'SELECT role,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $actor->execute([$adminUserId]);
            $admin = $actor->fetch(PDO::FETCH_ASSOC);
            if (!$admin || (string)$admin['role'] !== 'admin' || (int)$admin['is_active'] !== 1) {
                throw new DomainException('An active administrator is required to change rating visibility.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $stmt = $this->db->prepare(
                    'SELECT id,is_hidden,user_id AS patient_user_id,doctor_id AS doctor_user_id
                     FROM ratings WHERE id=? LIMIT 1 FOR UPDATE'
                );
            } else {
                $stmt = $this->db->prepare(
                    'SELECT id,is_public,patient_user_id,doctor_user_id
                     FROM ratings WHERE id=? LIMIT 1 FOR UPDATE'
                );
            }
            $stmt->execute([$ratingId]);
            $rating = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rating) {
                throw new OutOfBoundsException('Rating not found.');
            }

            $wasPublic = $this->schema === self::SCHEMA_V1
                ? (int)$rating['is_hidden'] === 0
                : (int)$rating['is_public'] === 1;

            if ($wasPublic === $isPublic) {
                return [
                    'id' => $ratingId,
                    'is_public' => $isPublic,
                    'is_hidden' => !$isPublic,
                    'changed' => false,
                ];
            }

            if ($this->schema === self::SCHEMA_V1) {
                $update = $this->db->prepare('UPDATE ratings SET is_hidden=? WHERE id=?');
                $update->execute([$isPublic ? 0 : 1, $ratingId]);
            } else {
                $update = $this->db->prepare('UPDATE ratings SET is_public=? WHERE id=?');
                $update->execute([$isPublic ? 1 : 0, $ratingId]);
            }

            $patientUserId = (int)$rating['patient_user_id'];
            $doctorUserId = (int)$rating['doctor_user_id'];
            $action = $isPublic ? 'restore' : 'hide';
            $this->writeVisibilityAudit(
                $adminUserId,
                $action,
                $ratingId,
                $patientUserId,
                $doctorUserId,
                $wasPublic,
                $isPublic
            );

            return [
                'id' => $ratingId,
                'is_public' => $isPublic,
                'is_hidden' => !$isPublic,
                'changed' => true,
            ];
        });
    }

    private function writeVisibilityAudit(
        int $adminUserId,
        string $action,
        int $ratingId,
        int $patientUserId,
        int $doctorUserId,
        bool $oldIsPublic,
        bool $newIsPublic
    ): void {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $oldJson = json_encode(['is_public' => $oldIsPublic], $flags);
        $newJson = json_encode(['is_public' => $newIsPublic], $flags);
        $description = $newIsPublic
            ? 'Administrator restored a doctor rating to public view.'
            : 'Administrator hid a doctor rating from public view.';

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                'INSERT INTO admin_audit
                 (actor_id,actor_role,action,entity_type,entity_id,patient_id,doctor_id,
                  old_value,new_value,description,ip_address,user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $adminUserId, 'admin', $action, 'rating', $ratingId,
                $patientUserId, $doctorUserId, $oldJson, $newJson, $description,
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO audit_events
             (actor_user_id,actor_role_snapshot,action_code,entity_type,entity_id,
              patient_user_id,doctor_user_id,old_value,new_value,description,ip_address,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $adminUserId, 'admin', $action, 'rating', (string)$ratingId,
            $patientUserId, $doctorUserId, $oldJson, $newJson, $description,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function submitForCompletedAppointment(
        int $appointmentId,int $patientUserId,int $stars,string $review
    ):array {
        if($appointmentId<=0||$patientUserId<=0){
            throw new InvalidArgumentException('Appointment and patient IDs must be positive.');
        }
        if($stars<1||$stars>5){
            throw new InvalidArgumentException('Rating must be between one and five stars.');
        }
        if(mb_strlen($review)>10000){
            throw new InvalidArgumentException('Rating review is too long.');
        }

        return $this->withTransaction(function()use($appointmentId,$patientUserId,$stars,$review):array{
            if($this->schema===self::SCHEMA_V1){
                $identity=$this->db->prepare(
                    'SELECT doctor_id AS doctor_user_id,user_id AS patient_user_id
                     FROM appointments WHERE id=? LIMIT 1'
                );
            }else{
                $identity=$this->db->prepare(
                    'SELECT doctor_user_id,patient_user_id
                     FROM appointments WHERE id=? LIMIT 1'
                );
            }
            $identity->execute([$appointmentId]);
            $context=$identity->fetch(PDO::FETCH_ASSOC);
            if(!$context||(int)$context['patient_user_id']!==$patientUserId){
                throw new DomainException('Appointment not found.');
            }
            $doctorId=(int)$context['doctor_user_id'];
            if($doctorId<=0){throw new DomainException('The appointment doctor could not be resolved.');}

            $access=new ClinicalAccessService($this->db,$this->schema);
            if(!$access->lockDoctorAndPatient($doctorId,$patientUserId)){
                throw new DomainException('The appointment account relationship is no longer available.');
            }

            $appointment=$this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
            $appointment->execute([$appointmentId]);
            $row=$appointment->fetch(PDO::FETCH_ASSOC);
            if(!$row){throw new DomainException('Appointment not found.');}

            if($this->schema===self::SCHEMA_V1){
                $status=(string)$row['status'];
                $workflow=$this->db->prepare('SELECT status FROM visit_workflow WHERE appointment_id=? LIMIT 1 FOR UPDATE');
                $workflow->execute([$appointmentId]);
                $workflowStatus=$workflow->fetchColumn();
                $doctorName=(string)$row['doctor'];
                $ratingExists=$this->db->prepare('SELECT id FROM ratings WHERE appointment_id=? LIMIT 1 FOR UPDATE');
                $insert=$this->db->prepare(
                    'INSERT INTO ratings(appointment_id,user_id,doctor_id,stars,review,created_at)
                     VALUES(?,?,?,?,?,NOW())'
                );
            }else{
                $status=(string)$row['booking_status'];
                $workflow=$this->db->prepare(
                    'SELECT workflow_status FROM clinical_encounters WHERE appointment_id=? LIMIT 1 FOR UPDATE'
                );
                $workflow->execute([$appointmentId]);
                $workflowStatus=$workflow->fetchColumn();
                $doctorName=(string)$row['doctor_name_snapshot'];
                $ratingExists=$this->db->prepare('SELECT id FROM ratings WHERE appointment_id=? LIMIT 1 FOR UPDATE');
                $insert=$this->db->prepare(
                    'INSERT INTO ratings(appointment_id,patient_user_id,doctor_user_id,stars,review,created_at)
                     VALUES(?,?,?,?,?,NOW())'
                );
            }

            $pendingOrNotComplete=$this->schema===self::SCHEMA_V1
                ? ($status!=='Confirmed'||$workflowStatus!=='Completed')
                : ($status!=='confirmed'||$workflowStatus!=='completed');
            if($pendingOrNotComplete){
                throw new DomainException('You can only rate a completed visit.');
            }
            $ratingExists->execute([$appointmentId]);
            if($ratingExists->fetchColumn()){
                throw new DomainException('You have already rated this appointment.');
            }

            if($this->schema===self::SCHEMA_V1){
                $insert->execute([$appointmentId,$patientUserId,$doctorId,$stars,$review]);
                $aggregate=$this->db->prepare('SELECT ROUND(AVG(stars),1) FROM ratings WHERE doctor_id=?');
                $aggregate->execute([$doctorId]);
                $average=(float)$aggregate->fetchColumn();
                $this->db->prepare('UPDATE doctors SET rating=? WHERE user_id=?')->execute([$average,$doctorId]);
            }else{
                $insert->execute([$appointmentId,$patientUserId,$doctorId,$stars,$review]);
                $aggregate=$this->db->prepare(
                    'SELECT average_rating,rating_count FROM v_doctor_rating_summary WHERE doctor_user_id=?'
                );
                $aggregate->execute([$doctorId]);
                $summary=$aggregate->fetch(PDO::FETCH_ASSOC)?:['average_rating'=>0,'rating_count'=>0];
                $average=(float)$summary['average_rating'];
            }

            $patient=$this->db->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
            $patient->execute([$patientUserId]);
            $patientName=(string)($patient->fetchColumn()?:'a patient');
            $notifications=new NotificationService($this->db);
            $notifications->create(
                $doctorId,
                NotificationService::TYPE_RATING_RECEIVED,
                'New Rating Received',
                "You received a {$stars}-star rating from {$patientName}.".($review!==''?" Review: {$review}":''),
                'rating',
                $appointmentId
            );

            $admin=$this->db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
            if($admin){
                $notifications->create(
                    (int)$admin,
                    NotificationService::TYPE_RATING_RECEIVED,
                    'New Doctor Rating',
                    "{$patientName} rated {$doctorName} {$stars} stars.",
                    'rating',
                    $appointmentId
                );
            }

            return [
                'appointment_id'=>$appointmentId,
                'doctor_user_id'=>$doctorId,
                'stars'=>$stars,
                'average_rating'=>$average,
            ];
        });
    }

    /** Return one patient's rating for a specific appointment, if present. */
    public function getAppointmentRating(int $appointmentId,int $patientUserId):?array
    {
        if($appointmentId<=0||$patientUserId<=0){
            throw new InvalidArgumentException('Appointment and patient IDs must be positive.');
        }
        $patientColumn=$this->schema===self::SCHEMA_V1?'user_id':'patient_user_id';
        $visibility = $this->schema === self::SCHEMA_V1
            ? ($this->supportsLegacyVisibilityColumn()
                ? 'is_hidden,(1-is_hidden) AS is_public'
                : '0 AS is_hidden,1 AS is_public')
            : 'is_public,(1-is_public) AS is_hidden';
        $stmt=$this->db->prepare(
            "SELECT id,stars,review,created_at,{$visibility} FROM ratings
             WHERE appointment_id=? AND {$patientColumn}=? LIMIT 1"
        );
        $stmt->execute([$appointmentId,$patientUserId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    /** Return anonymized doctor-facing rating rows with a stable appointment DTO. */
    public function getRatingsForDoctor(int $doctorUserId):array
    {
        if($doctorUserId<=0){throw new InvalidArgumentException('Doctor ID must be positive.');}
        if($this->schema===self::SCHEMA_V1){
            $visibility = $this->supportsLegacyVisibilityColumn()
                ? 'r.is_hidden,(1-r.is_hidden) AS is_public'
                : '0 AS is_hidden,1 AS is_public';
            $sql="SELECT r.id,r.stars,r.review,{$visibility},
                         r.created_at AS review_date,a.date AS appt_date,a.time AS appt_time,
                         a.appointment_time_range,a.id AS appointment_id,a.doctor_id
                  FROM ratings r JOIN appointments a ON r.appointment_id=a.id
                  WHERE r.doctor_id=? ORDER BY r.created_at DESC";
        }else{
            $sql="SELECT r.id,r.stars,r.review,r.is_public,(1-r.is_public) AS is_hidden,
                         r.created_at AS review_date,DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                         TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                         a.source_time_snapshot,
                         a.source_time_range_snapshot AS appointment_time_range,
                         a.duration_minutes,a.id AS appointment_id,
                         a.doctor_user_id AS doctor_id
                  FROM ratings r JOIN appointments a ON r.appointment_id=a.id
                  WHERE r.doctor_user_id=? ORDER BY r.created_at DESC";
        }
        $stmt=$this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Return the administrator rating list with the same fields for both schemas. */
    public function getAllRatings():array
    {
        if($this->schema===self::SCHEMA_V1){
            $visibility = $this->supportsLegacyVisibilityColumn()
                ? 'r.is_hidden,(1-r.is_hidden) AS is_public'
                : '0 AS is_hidden,1 AS is_public';
            $sql="SELECT r.id,r.stars,r.review,{$visibility},
                         r.created_at AS review_date,COALESCE(up.name,a.patient_name,'Unknown Patient') AS patient_name,
                         COALESCE(up.email,'—') AS patient_email,
                         COALESCE(ud.name,a.doctor,'Unknown Doctor') AS doctor_name,
                         a.date AS appt_date,a.time AS appt_time,
                         a.department,a.id AS appointment_id
                  FROM ratings r
                  JOIN appointments a ON r.appointment_id=a.id
                  LEFT JOIN users up ON r.user_id=up.id
                  LEFT JOIN users ud ON r.doctor_id=ud.id
                  ORDER BY r.created_at DESC";
        }else{
            $sql="SELECT r.id,r.stars,r.review,r.is_public,(1-r.is_public) AS is_hidden,
                         r.created_at AS review_date,COALESCE(up.name,a.patient_name_snapshot,'Unknown Patient') AS patient_name,
                         COALESCE(up.email,'—') AS patient_email,
                         COALESCE(ud.name,a.doctor_name_snapshot,'Unknown Doctor') AS doctor_name,
                         DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                         TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                         a.department_name_snapshot AS department,
                         a.id AS appointment_id
                  FROM ratings r
                  JOIN appointments a ON r.appointment_id=a.id
                  LEFT JOIN users up ON r.patient_user_id=up.id
                  LEFT JOIN users ud ON r.doctor_user_id=ud.id
                  ORDER BY r.created_at DESC";
        }
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function withTransaction(callable $operation):mixed{
        $owns=!$this->db->inTransaction();
        if($owns){$this->db->beginTransaction();}
        try{
            $result=$operation();
            if($owns){$this->db->commit();}
            return $result;
        }catch(Throwable $error){
            if($owns&&$this->db->inTransaction()){$this->db->rollBack();}
            throw $error;
        }
    }
}
