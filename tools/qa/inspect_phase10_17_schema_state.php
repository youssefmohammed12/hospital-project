<?php
declare(strict_types=1);

/** Read-only signature classifier for Phase 10â€“17. Exact approved synthetic fixture only. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }
require_once __DIR__ . '/../../includes/db.php';
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$connection = (string)$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
if (!preg_match('/^(127\.0\.0\.1|localhost)(\s|$)/i', $connection)) { fwrite(STDERR,"Non-loopback connection refused.\n"); exit(3); }
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'healthbridge') { fwrite(STDERR,"Expected initial application schema healthbridge.\n"); exit(4); }
$source = 'healthbridge_hb_auth_email_verify_20261114';
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$exists->execute([$source]);
if ((int)$exists->fetchColumn() !== 1) { fwrite(STDERR,"Approved synthetic source fixture missing.\n"); exit(5); }
$pdo->exec('USE `' . $source . '`');
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== $source) { fwrite(STDERR,"Source fixture identity mismatch.\n"); exit(6); }

function tableExists(PDO $pdo, string $table): bool {
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
    $s->execute([$table]); return (int)$s->fetchColumn()===1;
}
function columns(PDO $pdo, string $table): array {
    if(!tableExists($pdo,$table)) return [];
    $s=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $s->execute([$table]); $out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$out[$r['COLUMN_NAME']]=$r;}
    return $out;
}
function indexes(PDO $pdo, string $table): array {
    if(!tableExists($pdo,$table)) return [];
    $s=$pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    $s->execute([$table]);$out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$n=$r['INDEX_NAME'];if(!isset($out[$n]))$out[$n]=['unique'=>(int)$r['NON_UNIQUE']===0,'columns'=>[]];$out[$n]['columns'][]=$r['COLUMN_NAME'];}
    return $out;
}
function engine(PDO $pdo,string $table): array {
    $s=$pdo->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:[];
}
function foreignKeys(PDO $pdo, array $names): array {
    if(!$names)return [];$marks=implode(',',array_fill(0,count($names),'?'));
    $s=$pdo->prepare("SELECT CONSTRAINT_NAME,TABLE_NAME,DELETE_RULE,UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ({$marks})");$s->execute($names);$out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['CONSTRAINT_NAME']]=$r;return $out;
}
function state(int $found,int $expected,bool $definitionMatches=true): string {
    if($found===0)return 'verified_absent';
    if($found<$expected)return 'partial';
    return $definitionMatches?'verified_present':'ambiguous';
}
function addPhase(array &$report,string $phase,string $status,array $facts):void{$report['phases'][$phase]=['state'=>$status,'facts'=>$facts];}

$report=['source_schema'=>$source,'server_version'=>(string)$pdo->query('SELECT VERSION()')->fetchColumn(),'read_only'=>true,'generated_at'=>date(DATE_ATOM),'phases'=>[],'aggregate_safety_checks'=>[],'limitations'=>[]];

// Phase 10 â€” reschedule columns and named supporting indexes.
$a=columns($pdo,'appointments');$p10c=['pending_reschedule_date','pending_reschedule_time','requested_reschedule_date','requested_reschedule_time','reschedule_reason','reschedule_requested_at','reschedule_requested_by','reschedule_status','reschedule_responded_at','reschedule_responded_by','reschedule_response_notes'];
$i10=indexes($pdo,'appointments');$p10i=['idx_appt_reschedule_status','idx_appt_reschedule_requested','idx_appt_doctor_date_status','idx_appt_doctor_pending_reschedule','idx_appt_doctor_requested_reschedule'];
$fc=count(array_intersect($p10c,array_keys($a)));$fi=count(array_intersect($p10i,array_keys($i10)));$m10=$fc===count($p10c)&&$fi===count($p10i);
addPhase($report,'phase10_reschedule',state($fc+$fi,count($p10c)+count($p10i),$m10),['required_columns'=>$fc.'/'.count($p10c),'required_indexes'=>$fi.'/'.count($p10i)]);

// Phase 11 â€” session version and hashed reset-token model.
$u=columns($pdo,'users');$pr=columns($pdo,'password_resets');$ipr=indexes($pdo,'password_resets');$p11c=(int)isset($u['session_version'])+(int)isset($pr['token_hash'])+(int)isset($pr['request_ip']);$p11i=['uq_password_resets_token_hash','idx_pr_email_created','idx_pr_ip_created'];$p11ic=count(array_intersect($p11i,array_keys($ipr)));$m11=$p11c===3&&$p11ic===3&&isset($pr['token_hash']);
addPhase($report,'phase11_session_reset',state($p11c+$p11ic,6,$m11),['session_version_present'=>isset($u['session_version']),'token_hash_present'=>isset($pr['token_hash']),'request_ip_present'=>isset($pr['request_ip']),'reset_indexes'=>$p11ic.'/3','legacy_raw_token_column_present'=>isset($pr['token'])]);
if(isset($pr['token'])){$report['aggregate_safety_checks']['nonempty_legacy_raw_reset_tokens']=(int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE token IS NOT NULL AND token<>''")->fetchColumn();}
if(isset($pr['token'])){$report['aggregate_safety_checks']['duplicate_legacy_raw_reset_token_hash_groups']=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT SHA2(token,256) h FROM password_resets WHERE token IS NOT NULL AND token<>'' GROUP BY SHA2(token,256) HAVING COUNT(*)>1) d")->fetchColumn();}

// Phase 12 â€” follow-up instructions.
$vn=columns($pdo,'visit_notes');$p12=isset($vn['follow_up_instructions']);addPhase($report,'phase12_visit_follow_up',$p12?'verified_present':'verified_absent',['follow_up_instructions_present'=>$p12,'column_type'=>$vn['follow_up_instructions']['COLUMN_TYPE']??null]);

// Phase 13 â€” exact 28-name foreign-key set and RESTRICT semantics.
$p13names=['fk_doctors_user','fk_appointments_doctor','fk_appointments_user','fk_vw_appointment','fk_vd_appointment','fk_vd_doctor','fk_ratings_appointment','fk_ratings_user','fk_ratings_doctor','fk_mhu_patient','fk_mhu_doctor','fk_mr_patient','fk_vn_appointment','fk_vn_patient','fk_vn_doctor','fk_rx_patient','fk_rx_doctor','fk_rx_appt','fk_rxi_prescription','fk_dss_doctor','fk_dsw_doctor','fk_audit_actor','fk_mra_patient','fk_mra_admin','fk_pal_actor','fk_pal_patient','fk_sal_actor','fk_sal_doctor'];$fk=foreignKeys($pdo,$p13names);$p13count=count($fk);$allRestrict=$p13count===28;foreach($fk as $r){if($r['DELETE_RULE']!=='RESTRICT'||$r['UPDATE_RULE']!=='RESTRICT')$allRestrict=false;}
addPhase($report,'phase13_clinical_audit_retention',state($p13count,28,$allRestrict),['named_foreign_keys'=>$p13count.'/28','all_delete_and_update_restrict'=>$allRestrict]);
$report['aggregate_safety_checks']['phase13_audit_orphan_counts']='not_run';
if(tableExists($pdo,'patient_audit_log')&&tableExists($pdo,'schedule_audit_log')&&isset($u['id'])){$q=$pdo->query("SELECT CONCAT((SELECT COUNT(*) FROM patient_audit_log p LEFT JOIN users u ON u.id=p.actor_id WHERE u.id IS NULL),'|',(SELECT COUNT(*) FROM patient_audit_log p LEFT JOIN users u ON u.id=p.patient_id WHERE u.id IS NULL),'|',(SELECT COUNT(*) FROM schedule_audit_log s LEFT JOIN users u ON u.id=s.actor_id WHERE u.id IS NULL),'|',(SELECT COUNT(*) FROM schedule_audit_log s LEFT JOIN users u ON u.id=s.doctor_id WHERE u.id IS NULL))");$report['aggregate_safety_checks']['phase13_audit_orphan_counts']=$q->fetchColumn();}

// Phase 14 â€” stored generated normalizer, unique index, and duplicate preflight.
$phone=$u['phone_normalized']??null;$iu=indexes($pdo,'users');$phoneIndex=$iu['uq_users_phone_normalized']??null;$phoneExpression=(string)($phone['GENERATION_EXPRESSION']??'');$phoneDefinition=$phone!==null&&stripos((string)$phone['EXTRA'],'STORED GENERATED')!==false&&$phoneExpression!==''&&$phoneIndex!==null&&$phoneIndex['unique']&&$phoneIndex['columns']===['phone_normalized'];$p14found=(int)($phone!==null)+(int)($phoneIndex!==null);addPhase($report,'phase14_phone_uniqueness',state($p14found,2,$phoneDefinition),['generated_column_present'=>$phone!==null,'stored_generated'=>$phoneDefinition,'unique_index_present'=>$phoneIndex!==null,'unique_index_definition_matches'=>$phoneDefinition]);
if(isset($u['phone'])){$dupes=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT normalized_phone FROM (SELECT NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone),' ',''),CHAR(9),''),CHAR(10),''),CHAR(11),''),CHAR(12),''),CHAR(13),''),'(',''),')',''),'.',''),'-',''),'+20','0'),'') normalized_phone FROM users) n WHERE normalized_phone IS NOT NULL GROUP BY normalized_phone HAVING COUNT(*)>1) d")->fetchColumn();$report['aggregate_safety_checks']['duplicate_normalized_phone_groups']=$dupes;}

// Phase 15 â€” outbox exact required columns, indexes and engine/collation.
$o=columns($pdo,'notification_outbox');$io=indexes($pdo,'notification_outbox');$p15c=['id','user_id','type','title','message','ref_type','ref_id','status','attempt_count','available_at','created_at','processed_at','last_error_code'];$p15i=['idx_notification_outbox_ready','idx_notification_outbox_processed'];$p15cc=count(array_intersect($p15c,array_keys($o)));$p15ic=count(array_intersect($p15i,array_keys($io)));$oStatus=(string)($o['status']['COLUMN_TYPE']??'');$oDef=isset($o['id'])&&$p15cc===13&&$p15ic===2&&($oStatus==="enum('pending','delivered','skipped','failed')")&&strtoupper((string)(engine($pdo,'notification_outbox')['ENGINE']??''))==='INNODB';
addPhase($report,'phase15_notification_outbox',state($p15cc+$p15ic,15,$oDef),['required_columns'=>$p15cc.'/13','required_indexes'=>$p15ic.'/2','status_enum_matches'=>$oStatus==="enum('pending','delivered','skipped','failed')",'table'=>$oDef?engine($pdo,'notification_outbox'):engine($pdo,'notification_outbox')]);

// Phase 16 â€” encrypted pre-account registration queue.
$rq=columns($pdo,'registration_intake_queue');$irq=indexes($pdo,'registration_intake_queue');$p16c=['id','request_id','payload_version','key_version','nonce','auth_tag','ciphertext','status','attempt_count','available_at','lease_expires_at','worker_token','created_at','started_at','processed_at','last_error_code'];$p16i=['uq_registration_intake_request','idx_registration_intake_ready','idx_registration_intake_lease','idx_registration_intake_processed'];$p16cc=count(array_intersect($p16c,array_keys($rq)));$p16ic=count(array_intersect($p16i,array_keys($irq)));$rStatus=(string)($rq['status']['COLUMN_TYPE']??'');$rExpectedValues=['pending','processing','completed','duplicate','failed'];$rStatusMatches=true;foreach($rExpectedValues as $value){if(!str_contains($rStatus,"'".$value."'")){$rStatusMatches=false;}}$rPhase17Extension=str_contains($rStatus,"'awaiting_verification'")&&str_contains($rStatus,"'expired'");$rEngine=engine($pdo,'registration_intake_queue');$rDef=$p16cc===16&&$p16ic===4&&$rStatusMatches&&strtoupper((string)($rEngine['ENGINE']??''))==='INNODB';
addPhase($report,'phase16_registration_intake_queue',state($p16cc+$p16ic,20,$rDef),['required_columns'=>$p16cc.'/16','required_indexes'=>$p16ic.'/4','status_enum_contains_phase16_values'=>$rStatusMatches,'status_enum_extended_by_phase17'=>$rPhase17Extension,'table'=>$rEngine]);

// Phase 17 â€” email verification columns, queue state, challenge table and FK.
$usersVerify=(int)isset($u['email_verification_required'])+(int)isset($u['email_verified_at']);$rq17=['email_lookup_hmac','verification_expires_at','email_verified_at'];$rq17c=count(array_intersect($rq17,array_keys($rq)));$status17=(string)($rq['status']['COLUMN_TYPE']??'');$enum17=str_contains($status17,'awaiting_verification')&&str_contains($status17,'expired');$lookupIndex=isset($irq['idx_registration_intake_email_lookup']);$ch=columns($pdo,'registration_email_challenges');$ich=indexes($pdo,'registration_email_challenges');$challengeCols=['id','queue_id','token_hash','created_at','expires_at','consumed_at','revoked_at','send_attempt_count','last_send_attempt_at','last_sent_at','last_send_failure_at'];$challengeIdx=['uq_registration_email_challenge_hash','idx_registration_email_challenge_queue'];$chc=count(array_intersect($challengeCols,array_keys($ch)));$chi=count(array_intersect($challengeIdx,array_keys($ich)));$fk17=foreignKeys($pdo,['fk_registration_email_challenge_queue']);$fkOk=isset($fk17['fk_registration_email_challenge_queue'])&&$fk17['fk_registration_email_challenge_queue']['DELETE_RULE']==='CASCADE';$found17=$usersVerify+$rq17c+(int)$enum17+(int)$lookupIndex+$chc+$chi+(int)$fkOk;$expected17=2+3+1+1+11+2+1;$full17=$usersVerify===2&&$rq17c===3&&$enum17&&$lookupIndex&&$chc===11&&$chi===2&&$fkOk;
addPhase($report,'phase17_registration_email_verification',state($found17,$expected17,$full17),['users_verification_columns'=>$usersVerify.'/2','queue_verification_columns'=>$rq17c.'/3','queue_status_enum_complete'=>$enum17,'queue_lookup_index_present'=>$lookupIndex,'challenge_columns'=>$chc.'/11','challenge_indexes'=>$chi.'/2','challenge_fk_cascade_correct'=>$fkOk,'challenge_table'=>engine($pdo,'registration_email_challenges')]);
if(tableExists($pdo,'registration_email_challenges')&&tableExists($pdo,'registration_intake_queue')){$report['aggregate_safety_checks']['orphan_email_challenge_queue_rows']=(int)$pdo->query('SELECT COUNT(*) FROM registration_email_challenges c LEFT JOIN registration_intake_queue q ON q.id=c.queue_id WHERE q.id IS NULL')->fetchColumn();}

$report['limitations']=[
    'This is a read-only schema signature classifier for one approved synthetic V1 fixture, not an execution or restore test.',
    'verified_present means the inspected expected signature matched; it does not prove the historical migration chronology.',
    'Phases 10 through 17 are classified independently because later tables can coexist with partial or missing earlier signatures.',
    'Phase 13 exact FK classification expects the modern all-28-RESTRICT result; the Phase 8 compatibility bridge has its own separate rehearsal evidence.',
    'Phase 11 / Phase 14 / Phase 15 / Phase 16 / Phase 17 definitions are intentionally conservative; any ambiguous result requires a dedicated clone rehearsal.'
];
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
if($json===false){fwrite(STDERR,"Unable to encode report.\n");exit(7);}
$evidence='C:/xampp85/migration-backups/healthbridge/hb-db-ledger-phase13-20261120';
if(!is_dir($evidence)){fwrite(STDERR,"Evidence folder missing.\n");exit(8);}
$path=$evidence.'/phase10-17-state-classification.json';
if(file_put_contents($path,$json.PHP_EOL)===false){fwrite(STDERR,"Unable to save evidence report.\n");exit(9);}
echo $json.PHP_EOL;echo "REPORT_FILE={$path}\n";
