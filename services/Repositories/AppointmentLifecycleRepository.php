<?php
declare(strict_types=1);

require_once __DIR__ . '/../ClinicalAccessService.php';
require_once __DIR__ . '/../Repositories/ClinicalEncounterRepository.php';
require_once __DIR__ . '/../NotificationService.php';

/**
 * Transactional appointment approval/decline adapter.
 *
 * Account rows are locked before appointment rows. Confirmation creates the
 * initial clinical workflow in the same transaction; audit/event/notification
 * writes are also atomic with the status transition.
 */
final class AppointmentLifecycleRepository
{
    public const SCHEMA_V1='v1';
    public const SCHEMA_V2='v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db,string $schema)
    {
        if(!in_array($schema,[self::SCHEMA_V1,self::SCHEMA_V2],true)){
            throw new InvalidArgumentException('AppointmentLifecycleRepository schema must be explicitly v1 or v2.');
        }
        $this->db=$db;
        $this->schema=$schema;
    }

    /**
     * Transition a pending appointment to confirmed or cancelled.
     * actorRole is admin or the assigned doctor. For admin cancellation, the
     * optional reason is retained in both audit and patient notification.
     */
    public function transitionPending(
        int $appointmentId,
        int $actorUserId,
        string $actorRole,
        string $newStatus,
        string $reason=''
    ):array {
        if($appointmentId<=0||$actorUserId<=0){
            throw new InvalidArgumentException('Appointment and actor IDs must be positive.');
        }
        if(!in_array($actorRole,['admin','doctor'],true)){
            throw new DomainException('Only administrators and the assigned doctor can manage pending appointments.');
        }
        if(!in_array($newStatus,['confirmed','cancelled'],true)){
            throw new InvalidArgumentException('Only confirmed or cancelled are supported appointment transitions.');
        }
        if(mb_strlen($reason)>2000){
            throw new InvalidArgumentException('Appointment reason must not exceed 2000 characters.');
        }

        return $this->withTransaction(function()use($appointmentId,$actorUserId,$actorRole,$newStatus,$reason):array{
            // Lock actor admin first; doctor actors are locked by the shared
            // doctor -> patient helper below.
            if($actorRole==='admin'){
                $actor=$this->db->prepare(
                    "SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1 LIMIT 1 FOR UPDATE"
                );
                $actor->execute([$actorUserId]);
                if(!$actor->fetchColumn()){throw new DomainException('The authenticated administrator account is not active.');}
            }

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
            if(!$context){throw new DomainException('Appointment not found.');}

            $doctorId=(int)$context['doctor_user_id'];
            $patientId=!empty($context['patient_user_id'])?(int)$context['patient_user_id']:null;
            if($doctorId<=0){throw new DomainException('The appointment has no valid assigned doctor.');}
            if($actorRole==='doctor'&&$actorUserId!==$doctorId){
                throw new DomainException('You can only manage appointments assigned to you.');
            }

            $access=new ClinicalAccessService($this->db,$this->schema);
            if(!$access->lockDoctorAndPatient($doctorId,$patientId)){
                throw new DomainException('The appointment account relationship is no longer available.');
            }

            if($this->schema===self::SCHEMA_V1){
                $stmt=$this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
            }else{
                $stmt=$this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
            }
            $stmt->execute([$appointmentId]);
            $appointment=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$appointment){throw new DomainException('Appointment not found.');}

            $lockedDoctorId=(int)($this->schema===self::SCHEMA_V1?$appointment['doctor_id']:$appointment['doctor_user_id']);
            $lockedPatientId=(int)($this->schema===self::SCHEMA_V1?($appointment['user_id']??0):($appointment['patient_user_id']??0));
            if($lockedDoctorId!==$doctorId||$lockedPatientId!==(int)($patientId??0)){
                throw new DomainException('The appointment account relationship changed. Refresh and try again.');
            }

            $oldStatus=$this->schema===self::SCHEMA_V1
                ? (string)$appointment['status']
                : (string)$appointment['booking_status'];
            $pending=$this->schema===self::SCHEMA_V1?'Pending':'pending';
            if($oldStatus!==$pending){
                throw new DomainException('Only pending appointments can be approved or declined.');
            }

            if($this->schema===self::SCHEMA_V1){
                $update=$this->db->prepare(
                    'UPDATE appointments SET status=? WHERE id=? AND status=?'
                );
                $storedStatus=$newStatus==='confirmed'?'Confirmed':'Cancelled';
            }else{
                $update=$this->db->prepare(
                    'UPDATE appointments SET booking_status=? WHERE id=? AND booking_status=?'
                );
                $storedStatus=$newStatus;
            }
            $update->execute([$storedStatus,$appointmentId,$pending]);
            if($update->rowCount()!==1){
                throw new DomainException('The appointment was changed by another request. Refresh and try again.');
            }

            $dateTime=$this->schema===self::SCHEMA_V1
                ? (string)$appointment['date'].' '.(string)$appointment['time']
                : (string)$appointment['starts_at'];
            $date=substr($dateTime,0,10);
            $time=substr($dateTime,11,5);
            $doctorName=$this->schema===self::SCHEMA_V1
                ? (string)$appointment['doctor']
                : (string)$appointment['doctor_name_snapshot'];

            if($newStatus==='confirmed'){
                (new ClinicalEncounterRepository($this->db,$this->schema))->getOrCreateWorkflow($appointmentId);
            }

            $action=$newStatus==='confirmed'
                ? ($actorRole==='admin'?'approve':'confirm')
                : ($actorRole==='admin'?'decline':'cancel');
            $description=$newStatus==='confirmed'
                ? "Appointment on {$date} at {$time} approved: {$oldStatus} → {$storedStatus}"
                : "Appointment on {$date} at {$time} declined: {$oldStatus} → {$storedStatus}";
            if($reason!==''){$description.='. Reason: '.$reason;}

            $this->writeAudit(
                $actorUserId,$actorRole,$action,'appointment',$appointmentId,
                ['status'=>$oldStatus],['status'=>$storedStatus],$description,$patientId,$doctorId
            );

            if($this->schema===self::SCHEMA_V2){
                $eventType=$newStatus==='confirmed'
                    ? ($actorRole==='admin'?'admin_approved':'doctor_confirmed')
                    : ($actorRole==='admin'?'admin_declined':'doctor_cancelled');
                $this->writeAppointmentEvent(
                    $appointmentId,$actorUserId,$actorRole,$eventType,
                    $oldStatus,$storedStatus,
                    ['reason'=>$reason!==''?$reason:null]
                );
            }

            $notifications=new NotificationService($this->db);
            if($patientId!==null){
                if($newStatus==='confirmed'){
                    $title='Appointment Confirmed';
                    $type=NotificationService::TYPE_APPOINTMENT_CONFIRMED;
                    $message="Your appointment with {$doctorName} on {$date} at {$time} has been confirmed.";
                }elseif($actorRole==='admin'){
                    $title='Appointment Declined';
                    $type=NotificationService::TYPE_APPOINTMENT_DECLINED;
                    $message="Your appointment with {$doctorName} on {$date} at {$time} has been declined.";
                    if($reason!==''){$message.=' Reason: '.$reason;}
                }else{
                    $title='Appointment Cancelled';
                    $type=NotificationService::TYPE_APPOINTMENT_CANCELLED;
                    $message="Your appointment with {$doctorName} on {$date} at {$time} has been cancelled by the doctor.";
                }
                $notifications->create($patientId,$type,$title,$message,'appointment',$appointmentId);
            }

            return [
                'appointment_id'=>$appointmentId,
                'patient_user_id'=>$patientId,
                'doctor_user_id'=>$doctorId,
                'status'=>$storedStatus,
                'date'=>$date,
                'time'=>$time,
                'doctor_name'=>$doctorName,
                'action'=>$action,
            ];
        });
    }

    private function writeAudit(
        int $actorUserId,string $actorRole,string $action,string $entityType,int $entityId,
        mixed $oldValue,mixed $newValue,string $description,?int $patientId,?int $doctorId
    ):void{
        $oldJson=json_encode($oldValue,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $newJson=json_encode($newValue,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($this->schema===self::SCHEMA_V1){
            $stmt=$this->db->prepare(
                'INSERT INTO admin_audit
                 (actor_id,actor_role,action,entity_type,entity_id,patient_id,doctor_id,
                  old_value,new_value,description,ip_address,user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
        }else{
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
        int $appointmentId,int $actorUserId,string $actorRole,string $eventType,
        string $fromStatus,string $toStatus,array $details
    ):void{
        $stmt=$this->db->prepare(
            'INSERT INTO appointment_events
             (appointment_id,actor_user_id,actor_role_snapshot,event_type,from_status,to_status,event_details,created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            $appointmentId,$actorUserId,$actorRole,$eventType,$fromStatus,$toStatus,
            json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        ]);
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
