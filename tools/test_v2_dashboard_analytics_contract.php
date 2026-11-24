<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(10); }
const DA_V1='healthbridge_hb_auth_email_verify_20261114';
const DA_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/DashboardAnalyticsService.php';

function daConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[DA_V1,DA_V2],true)){throw new RuntimeException('Database is not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function daAssert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
function daRun(string $database,string $schema):void{
    $db=daConnect($database);
    $service=new DashboardAnalyticsService($db,$schema);
    $all=$service->getAll();
    $expected=['kpi','appointment_analytics','patient_analytics','doctor_analytics','department_analytics','recent_activity','system_status'];
    daAssert(array_keys($all)===$expected,"Dashboard top-level DTO keys changed for {$schema}.");
    foreach(['total_patients','total_doctors','total_departments','today_appointments','pending_appointments','confirmed_appointments','active_doctors','avg_rating','new_patients_this_month'] as $field){
        daAssert(array_key_exists($field,$all['kpi']),"Missing KPI {$field} in {$schema}.");
        daAssert(is_numeric($all['kpi'][$field]),"Non-numeric KPI {$field} in {$schema}.");
    }
    foreach(['weekly','monthly','status_distribution'] as $field){
        daAssert(is_array($all['appointment_analytics'][$field]),"Appointment analytics {$field} is not an array in {$schema}.");
    }
    daAssert(is_numeric($all['appointment_analytics']['completion_rate']),"Completion rate invalid in {$schema}.");
    foreach(['gender_distribution','age_distribution','most_active'] as $field){
        daAssert(is_array($all['patient_analytics'][$field]),"Patient analytics {$field} is not an array in {$schema}.");
    }
    foreach(['highest_rated','busiest_doctors'] as $field){
        daAssert(is_array($all['doctor_analytics'][$field]) && count($all['doctor_analytics'][$field])<=5,"Doctor analytics {$field} invalid in {$schema}.");
    }
    foreach($all['doctor_analytics']['highest_rated'] as $doctor){
        foreach(['id','name','specialty','rating','exp','review_count','total_appointments'] as $field){
            daAssert(array_key_exists($field,$doctor),"Highest-rated doctor missing {$field} in {$schema}.");
        }
    }
    foreach($all['doctor_analytics']['busiest_doctors'] as $doctor){
        foreach(['id','name','specialty','appointment_count'] as $field){
            daAssert(array_key_exists($field,$doctor),"Busiest doctor missing {$field} in {$schema}.");
        }
    }
    daAssert(is_array($all['department_analytics']),"Department analytics invalid in {$schema}.");
    foreach($all['department_analytics'] as $department){
        foreach(['id','name','status','doctor_count','appointment_count','patient_count'] as $field){
            daAssert(array_key_exists($field,$department),"Department DTO missing {$field} in {$schema}.");
        }
    }
    daAssert(is_array($all['recent_activity']) && count($all['recent_activity'])<=10,"Recent activity invalid in {$schema}.");
    foreach($all['recent_activity'] as $activity){
        foreach(['id','action','entity_type','entity_id','description','created_at','actor_role','actor_name','patient_id','doctor_id','time_ago'] as $field){
            daAssert(array_key_exists($field,$activity),"Recent activity missing {$field} in {$schema}.");
        }
    }
    foreach(['hospital_name','is_open','open_time','close_time','current_time','current_time_formatted','unread_count'] as $field){
        daAssert(array_key_exists($field,$all['system_status']),"System status missing {$field} in {$schema}.");
    }

    if($schema==='v1'){
        $expectedDoctors=(int)$db->query('SELECT COUNT(*) FROM doctors')->fetchColumn();
        $expectedAvailable=(int)$db->query('SELECT COUNT(*) FROM doctors WHERE available=1')->fetchColumn();
        $expectedPending=(int)$db->query("SELECT COUNT(*) FROM appointments WHERE status='Pending'")->fetchColumn();
        $expectedToday=(int)$db->query('SELECT COUNT(*) FROM appointments WHERE date=CURDATE()')->fetchColumn();
    }else{
        $expectedDoctors=(int)$db->query('SELECT COUNT(*) FROM doctor_profiles')->fetchColumn();
        $expectedAvailable=(int)$db->query('SELECT COUNT(*) FROM doctor_schedule_settings WHERE is_available=1')->fetchColumn();
        $expectedPending=(int)$db->query("SELECT COUNT(*) FROM appointments WHERE booking_status='pending'")->fetchColumn();
        $expectedToday=(int)$db->query('SELECT COUNT(*) FROM appointments WHERE starts_at>=CURDATE() AND starts_at<CURDATE()+INTERVAL 1 DAY')->fetchColumn();
    }
    daAssert((int)$all['kpi']['total_doctors']===$expectedDoctors,"Total doctor KPI mismatch in {$schema}.");
    daAssert((int)$all['kpi']['active_doctors']===$expectedAvailable,"Active doctor KPI mismatch in {$schema}.");
    daAssert((int)$all['kpi']['pending_appointments']===$expectedPending,"Pending appointment KPI mismatch in {$schema}.");
    daAssert((int)$all['kpi']['today_appointments']===$expectedToday,"Today's appointment KPI mismatch in {$schema}.");
    $recent=$service->getRecentActivity(3);
    daAssert(count($recent)<=3,"getRecentActivity limit ignored in {$schema}.");
    echo "DASHBOARD_ANALYTICS_DTO_AND_AGGREGATES_OK|{$schema}|doctors={$expectedDoctors}|departments=".count($all['department_analytics'])."\n";
}
try{
    echo "=== Dashboard analytics v1/v2 contract ===\n";
    daRun(DA_V1,'v1');
    daRun(DA_V2,'v2');
    echo "RESULT=PASS (read-only against both approved fixtures)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"DASHBOARD ANALYTICS CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
