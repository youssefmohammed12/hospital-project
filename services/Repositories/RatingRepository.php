<?php
declare(strict_types=1);

require_once __DIR__ . '/../ClinicalAccessService.php';
require_once __DIR__ . '/../NotificationService.php';

/** Rating creation for completed patient appointments (V2 schema). */
final class RatingRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db=$db;
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
            $actor = $this->db->prepare(
                'SELECT role,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $actor->execute([$adminUserId]);
            $admin = $actor->fetch(PDO::FETCH_ASSOC);
            if (!$admin || (string)$admin['role'] !== 'admin' || (int)$admin['is_active'] !== 1) {
                throw new DomainException('An active administrator is required to change rating visibility.');
            }

            $stmt = $this->db->prepare(
                'SELECT id,is_public,patient_user_id,doctor_user_id
                     FROM ratings WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$ratingId]);
            $rating = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rating) {
                throw new OutOfBoundsException('Rating not found.');
            }

            $wasPublic = (int)$rating['is_public'] === 1;

            if ($wasPublic === $isPublic) {
                return [
                    'id' => $ratingId,
                    'is_public' => $isPublic,
                    'is_hidden' => !$isPublic,
                    'changed' => false,
                ];
            }

            $update = $this->db->prepare('UPDATE ratings SET is_public=? WHERE id=?');
            $update->execute([$isPublic ? 1 : 0, $ratingId]);

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
            $identity=$this->db->prepare(
                'SELECT doctor_user_id,patient_user_id
                     FROM appointments WHERE id=? LIMIT 1'
            );
            $identity->execute([$appointmentId]);
            $context=$identity->fetch(PDO::FETCH_ASSOC);
            if(!$context||(int)$context['patient_user_id']!==$patientUserId){
                throw new DomainException('Appointment not found.');
            }
            $doctorId=(int)$context['doctor_user_id'];
            if($doctorId<=0){throw new DomainException('The appointment doctor could not be resolved.');}

            $access=new ClinicalAccessService($this->db,'v2');
            if(!$access->lockDoctorAndPatient($doctorId,$patientUserId)){
                throw new DomainException('The appointment account relationship is no longer available.');
            }

            $appointment=$this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
            $appointment->execute([$appointmentId]);
            $row=$appointment->fetch(PDO::FETCH_ASSOC);
            if(!$row){throw new DomainException('Appointment not found.');}

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

            $pendingOrNotComplete=($status!=='confirmed'||$workflowStatus!=='completed');
            if($pendingOrNotComplete){
                throw new DomainException('You can only rate a completed visit.');
            }
            $ratingExists->execute([$appointmentId]);
            if($ratingExists->fetchColumn()){
                throw new DomainException('You have already rated this appointment.');
            }

            $insert->execute([$appointmentId,$patientUserId,$doctorId,$stars,$review]);
            $aggregate=$this->db->prepare(
                'SELECT average_rating,rating_count FROM v_doctor_rating_summary WHERE doctor_user_id=?'
            );
            $aggregate->execute([$doctorId]);
            $summary=$aggregate->fetch(PDO::FETCH_ASSOC)?:['average_rating'=>0,'rating_count'=>0];
            $average=(float)$summary['average_rating'];

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
        $patientColumn='patient_user_id';
        $visibility = 'is_public,(1-is_public) AS is_hidden';
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
        $sql="SELECT r.id,r.stars,r.review,r.is_public,(1-r.is_public) AS is_hidden,
                         r.created_at AS review_date,DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                         TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                         a.source_time_snapshot,
                         a.source_time_range_snapshot AS appointment_time_range,
                         a.duration_minutes,a.id AS appointment_id,
                         a.doctor_user_id AS doctor_id
                  FROM ratings r JOIN appointments a ON r.appointment_id=a.id
                  WHERE r.doctor_user_id=? ORDER BY r.created_at DESC";
        $stmt=$this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Return the administrator rating list (V2 schema). */
    public function getAllRatings():array
    {
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
