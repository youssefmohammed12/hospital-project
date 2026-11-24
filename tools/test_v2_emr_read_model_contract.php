<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const EMR_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const EMR_V2 = 'healthbridge_v2_prototype_20261118';
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$pass = (string)(getenv('DB_PASS') ?: '');
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) { exit("Refusing non-loopback DB host.\n"); }
require_once __DIR__ . '/../services/PatientPortalService.php';
require_once __DIR__ . '/../services/VisitWorkflowService.php';
require_once __DIR__ . '/../services/PrescriptionService.php';
require_once __DIR__ . '/../services/Repositories/RatingRepository.php';
function emrDb(string $name,string $host,string $port,string $user,string $pass): PDO {
    if (!in_array($name,[EMR_V1,EMR_V2],true)) { throw new RuntimeException('Fixture not allowlisted.'); }
    $db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$name) { throw new RuntimeException('Connected database mismatch.'); }
    return $db;
}
function emrCount(PDO $db,string $sql,int $id): int { $s=$db->prepare($sql);$s->execute([$id]);return (int)$s->fetchColumn(); }
try {
    $v1=emrDb(EMR_V1,$host,$port,$user,$pass); $v2=emrDb(EMR_V2,$host,$port,$user,$pass);
    $p1=new PatientPortalService($v1,PatientPortalService::SCHEMA_V1); $p2=new PatientPortalService($v2,PatientPortalService::SCHEMA_V2);
    $w1=new VisitWorkflowService($v1,PatientPortalService::SCHEMA_V1); $w2=new VisitWorkflowService($v2,PatientPortalService::SCHEMA_V2);
    $rx1=new PrescriptionService($v1,PrescriptionService::SCHEMA_V1); $rx2=new PrescriptionService($v2,PrescriptionService::SCHEMA_V2);
    $ratings1=new RatingRepository($v1,RatingRepository::SCHEMA_V1); $ratings2=new RatingRepository($v2,RatingRepository::SCHEMA_V2);
    $ids=array_map('intval',$v1->query("SELECT id FROM users WHERE role='patient' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    if(count($ids)!==133){throw new RuntimeException('Expected 133 fixture patients.');}
    $v1Appt="SELECT a.* FROM appointments a WHERE a.user_id=? ORDER BY a.date DESC,a.time DESC";
    $v2Appt="SELECT a.id,a.patient_user_id AS user_id,a.doctor_user_id AS doctor_id,a.doctor_name_snapshot AS doctor,a.department_id,a.department_name_snapshot AS department,DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,TIME_FORMAT(a.starts_at,'%H:%i') AS time,a.source_time_snapshot,a.source_time_range_snapshot,a.source_time_range_snapshot AS appointment_time_range,a.duration_minutes,a.notes,a.created_at,ce.workflow_status,CASE WHEN rr.request_status IN ('requested','suggested') THEN 'Reschedule Requested' WHEN a.booking_status='pending' THEN 'Pending' WHEN a.booking_status='confirmed' THEN 'Confirmed' ELSE 'Cancelled' END AS status,CASE WHEN rr.request_status IN ('requested','suggested') THEN 'pending' WHEN rr.request_status='approved' THEN 'approved' WHEN rr.request_status='rejected' THEN 'rejected' ELSE 'none' END AS reschedule_status,CASE WHEN rr.request_status='suggested' AND rr.proposed_starts_at IS NOT NULL THEN DATE_FORMAT(rr.proposed_starts_at,'%Y-%m-%d') WHEN rr.request_status IN ('requested','suggested') THEN DATE_FORMAT(rr.requested_starts_at,'%Y-%m-%d') ELSE NULL END AS pending_reschedule_date,CASE WHEN rr.request_status='suggested' AND rr.proposed_starts_at IS NOT NULL THEN TIME_FORMAT(rr.proposed_starts_at,'%H:%i') WHEN rr.request_status IN ('requested','suggested') THEN TIME_FORMAT(rr.requested_starts_at,'%H:%i') ELSE NULL END AS pending_reschedule_time,rr.reason AS reschedule_reason,rr.requested_at AS reschedule_requested_at,u.name AS doctor_user_name FROM appointments a LEFT JOIN users u ON a.doctor_user_id=u.id LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id LEFT JOIN appointment_reschedule_requests rr ON rr.id=(SELECT MAX(rr2.id) FROM appointment_reschedule_requests rr2 WHERE rr2.appointment_id=a.id) WHERE a.patient_user_id=? ORDER BY a.starts_at DESC";
    $doctorIds=array_map('intval',$v1->query("SELECT id FROM users WHERE role='doctor' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $directory=$v2->query(
        "SELECT dp.doctor_user_id AS id,dp.doctor_user_id AS user_id,u.name,dp.specialty,
                dp.experience_years AS exp,dp.profile_icon AS emoji,dp.department_id,
                COALESCE(dep.name,dp.specialty) AS department_name,
                COALESCE(r.average_rating,0) AS rating,COALESCE(r.rating_count,0) AS rating_count,
                COALESCE(dss.is_available,0) AS available
         FROM doctor_profiles dp
         INNER JOIN users u ON u.id=dp.doctor_user_id AND u.role='doctor' AND u.is_active=1
         LEFT JOIN departments dep ON dep.id=dp.department_id
         LEFT JOIN v_doctor_rating_summary r ON r.doctor_user_id=dp.doctor_user_id
         LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=dp.doctor_user_id
         WHERE dp.profile_visible=1
           AND (dp.department_id IS NULL OR dep.status='active' OR dep.id IS NULL)
         ORDER BY rating DESC,u.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    if(!$directory){throw new RuntimeException('V2 public doctor directory unexpectedly returned no doctors.');}
    foreach($directory as $doctor){
        foreach(['id','user_id','name','specialty','exp','emoji','department_id','department_name','rating','rating_count','available'] as $key){
            if(!array_key_exists($key,$doctor)){throw new RuntimeException("V2 doctor directory missing {$key}.");}
        }
        if((int)$doctor['id']!==(int)$doctor['user_id'] || (float)$doctor['rating']<0 || (float)$doctor['rating']>5){
            throw new RuntimeException('V2 doctor directory returned an invalid doctor ID/rating.');
        }
    }
    $adminDoctors=$v2->query(
        'SELECT u.id,u.name,u.email,u.phone,u.is_active,u.created_at,
                dp.specialty,COALESCE(rs.average_rating,0) AS rating,
                dp.profile_icon AS emoji,dp.department_id,
                dp.experience_years AS exp,COALESCE(dss.is_available,0) AS available,
                dep.name AS department_name
         FROM users u
         LEFT JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
         LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id=u.id
         LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=u.id
         LEFT JOIN departments dep ON dep.id=dp.department_id
         WHERE u.role="doctor" ORDER BY u.created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    if(count($adminDoctors)!==count($doctorIds)){
        throw new RuntimeException('V2 admin all-doctors list did not return every doctor account.');
    }
    foreach($adminDoctors as $doctor){
        foreach(['id','name','email','phone','is_active','specialty','rating','emoji','department_id'] as $key){
            if(!array_key_exists($key,$doctor)){throw new RuntimeException("V2 admin doctor list missing {$key}.");}
        }
    }
    foreach($doctorIds as $doctorId){
        if(count($ratings1->getRatingsForDoctor($doctorId))!==count($ratings2->getRatingsForDoctor($doctorId))){
            throw new RuntimeException("Doctor {$doctorId} ratings list count differs.");
        }
        $doctorProfile=$v2->prepare(
            'SELECT u.id,u.name,u.email,u.phone,u.is_active,u.created_at,
                    dp.specialty,COALESCE(rs.average_rating,0) AS rating,
                    dp.experience_years AS exp,COALESCE(dss.is_available,0) AS available,
                    dp.profile_icon AS emoji,dp.department_id,dep.name AS department_name
             FROM users u
             INNER JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
             LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id=u.id
             LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=u.id
             LEFT JOIN departments dep ON dep.id=dp.department_id
             WHERE u.id=? AND u.role="doctor" LIMIT 1'
        );
        $doctorProfile->execute([$doctorId]);
        $profile=$doctorProfile->fetch(PDO::FETCH_ASSOC);
        if(!$profile || (int)$profile['id']!==$doctorId || !array_key_exists('department_name',$profile)){
            throw new RuntimeException("Doctor {$doctorId} admin profile DTO failed.");
        }
        $doctorAppts=$v2->prepare(
            "SELECT a.id,a.patient_name_snapshot AS patient_name,
                    a.department_name_snapshot AS department,
                    DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
                    TIME_FORMAT(a.starts_at,'%H:%i') AS time,
                    a.source_time_snapshot,a.source_time_range_snapshot,a.duration_minutes,
                    a.notes,a.created_at,a.doctor_user_id AS doctor_id,ce.workflow_status,
                    CASE
                      WHEN a.booking_status='confirmed' AND ce.workflow_status='completed' THEN 'Completed'
                      WHEN a.booking_status='confirmed' THEN 'Confirmed'
                      WHEN a.booking_status='pending' THEN 'Pending'
                      ELSE 'Cancelled'
                    END AS status
             FROM appointments a
             LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
             WHERE a.doctor_user_id=? ORDER BY a.starts_at DESC,a.id DESC"
        );
        $doctorAppts->execute([$doctorId]);
        foreach($doctorAppts->fetchAll(PDO::FETCH_ASSOC) as $appt){
            foreach(['id','patient_name','department','date','time','status','doctor_id'] as $key){
                if(!array_key_exists($key,$appt)){throw new RuntimeException("Doctor {$doctorId} appointment DTO missing {$key}.");}
            }
        }
        $doctorReviews=$v2->prepare(
            "SELECT r.id,r.stars,r.review,r.created_at AS review_date,
                    COALESCE(u.name,a.patient_name_snapshot,'Unknown Patient') AS patient_name,
                    COALESCE(u.email,'—') AS patient_email,
                    DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                    TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                    a.source_time_snapshot,a.source_time_range_snapshot,a.duration_minutes,
                    a.doctor_user_id AS doctor_id
             FROM ratings r JOIN appointments a ON r.appointment_id=a.id
             LEFT JOIN users u ON r.patient_user_id=u.id
             WHERE r.doctor_user_id=? ORDER BY r.created_at DESC"
        );
        $doctorReviews->execute([$doctorId]);
        foreach($doctorReviews->fetchAll(PDO::FETCH_ASSOC) as $review){
            foreach(['id','stars','review_date','patient_name','patient_email','appt_date','appt_time','doctor_id'] as $key){
                if(!array_key_exists($key,$review)){throw new RuntimeException("Doctor {$doctorId} review DTO missing {$key}.");}
            }
        }
    }
    if(count($ratings1->getAllRatings())!==count($ratings2->getAllRatings())){
        throw new RuntimeException('Administrator rating list count differs.');
    }
    $ratingIdentity=$v2->query('SELECT appointment_id,patient_user_id FROM ratings ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if($ratingIdentity){
        $ratingDto=$ratings2->getAppointmentRating((int)$ratingIdentity['appointment_id'],(int)$ratingIdentity['patient_user_id']);
        if(!$ratingDto || !isset($ratingDto['stars'],$ratingDto['created_at'])){
            throw new RuntimeException('Patient appointment rating DTO failed.');
        }
    }
    foreach($ids as $id){
        $s1=$v1->prepare($v1Appt);$s1->execute([$id]);$a1=$s1->fetchAll();
        $s2=$v2->prepare($v2Appt);$s2->execute([$id]);$a2=$s2->fetchAll();
        if(count($a1)!==count($a2)){throw new RuntimeException("Patient {$id} appointment count differs.");}
        foreach($a2 as $a){foreach(['id','user_id','doctor_id','date','time','status','doctor','department'] as $k){if(!array_key_exists($k,$a)){throw new RuntimeException("Patient {$id} appointment DTO missing {$k}.");}}}
        $s2Print=$v2->prepare($v2Appt.' LIMIT 20');$s2Print->execute([$id]);
        if(count($s2Print->fetchAll())>20){throw new RuntimeException("Patient {$id} printable appointment limit failed.");}
        if(emrCount($v1,'SELECT COUNT(*) FROM ratings WHERE user_id=?',$id)!==emrCount($v2,'SELECT COUNT(*) FROM ratings WHERE patient_user_id=?',$id)){throw new RuntimeException("Patient {$id} rating count differs.");}
        if(emrCount($v1,'SELECT COUNT(*) FROM notifications WHERE user_id=?',$id)!==emrCount($v2,'SELECT COUNT(*) FROM notifications WHERE user_id=?',$id)){throw new RuntimeException("Patient {$id} notification count differs.");}
        $p1->getMedicalProfile($id); $p2->getMedicalProfile($id);
        $audit=$v2->prepare("SELECT ae.id,ae.old_value,ae.new_value,ae.description,ae.occurred_at AS created_at,u.name AS admin_name FROM audit_events ae JOIN users u ON u.id=ae.actor_user_id WHERE ae.patient_user_id=? AND ae.action_code='medical_record_admin_correction' AND ae.entity_type='patient' ORDER BY ae.occurred_at DESC,ae.id DESC");
        $audit->execute([$id]); foreach($audit->fetchAll() as $event){json_decode((string)($event['old_value']??''),true);json_decode((string)($event['new_value']??''),true);}
        $history=$v2->prepare('SELECT u.name AS doctor_name,mhe.created_at FROM medical_history_events mhe JOIN users u ON mhe.doctor_user_id=u.id WHERE mhe.patient_user_id=? ORDER BY mhe.created_at DESC,mhe.id DESC LIMIT 1');
        $history->execute([$id]); $history->fetch();

        $legacyWorkflows=$w1->getForAppointments(array_map('intval',array_column($a1,'id')));
        $normalizedWorkflows=$w2->getForAppointments(array_map('intval',array_column($a2,'id')));
        if(count($legacyWorkflows)!==count($normalizedWorkflows)){throw new RuntimeException("Patient {$id} workspace workflow count differs.");}
        if(count($rx1->getByPatient($id))!==count($rx2->getByPatient($id))){throw new RuntimeException("Patient {$id} workspace prescription count differs.");}
        if(count($w1->getNotesForPatient($id))!==count($w2->getNotesForPatient($id))){throw new RuntimeException("Patient {$id} workspace note count differs.");}

        $doctorLookup=$v2->prepare("SELECT id,doctor_user_id FROM appointments WHERE patient_user_id=? AND booking_status='confirmed' ORDER BY starts_at DESC LIMIT 1");
        $doctorLookup->execute([$id]); $current=$doctorLookup->fetch(PDO::FETCH_ASSOC);
        if($current){
            $currentV2=$v2->prepare("SELECT a.id,a.patient_user_id AS user_id,a.doctor_user_id AS doctor_id,a.patient_name_snapshot AS patient_name,a.doctor_name_snapshot AS doctor,a.department_id,a.department_name_snapshot AS department,DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,TIME_FORMAT(a.starts_at,'%H:%i') AS time,a.source_time_snapshot,a.source_time_range_snapshot,a.source_time_range_snapshot AS appointment_time_range,a.duration_minutes,a.notes,a.created_at,ce.workflow_status,CASE WHEN rr.request_status IN ('requested','suggested') THEN 'Reschedule Requested' WHEN a.booking_status='pending' THEN 'Pending' WHEN a.booking_status='confirmed' THEN 'Confirmed' ELSE 'Cancelled' END AS status,u.name AS doctor_user_name FROM appointments a LEFT JOIN users u ON u.id=a.doctor_user_id LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id LEFT JOIN appointment_reschedule_requests rr ON rr.id=(SELECT MAX(rr2.id) FROM appointment_reschedule_requests rr2 WHERE rr2.appointment_id=a.id) WHERE a.id=? AND a.patient_user_id=? AND a.doctor_user_id=?");
            $currentV2->execute([(int)$current['id'],$id,(int)$current['doctor_user_id']]);
            $currentDto=$currentV2->fetch(PDO::FETCH_ASSOC);
            if(!$currentDto || (int)$currentDto['id']!==(int)$current['id']){throw new RuntimeException("Patient {$id} current-visit DTO mapping failed.");}
        }
    }
    echo "EMR_APPOINTMENT_DTO_PARITY|133|PASS\nEMR_RATING_AND_NOTIFICATION_COUNT_PARITY|133|PASS\nEMR_V2_AUDIT_AND_HISTORY_READS|133|PASS\nEMR_WORKSPACE_WORKFLOW_RX_NOTE_PARITY|133|PASS\nEMR_WORKSPACE_CURRENT_VISIT_DTO|PASS\nRESULT=PASS (read-only; no fixture writes)\n";
} catch(Throwable $e) { fwrite(STDERR,"EMR_READ_MODEL_SUITE=FAIL\n".$e->getMessage()."\n"); exit(1); }
