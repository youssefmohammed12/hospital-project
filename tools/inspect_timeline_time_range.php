<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(10);
$host='127.0.0.1';$dbs=['healthbridge_hb_auth_email_verify_20261114','healthbridge_v2_prototype_20261118'];
foreach($dbs as $name) {
 $db=new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 echo "DATABASE={$name}\n";
 if ($name===$dbs[0]) {
  $stmt=$db->query("SELECT id,date,time,appointment_time_range FROM appointments WHERE id IN (1,2,3,4,5,6,272,274) ORDER BY id");
 } else {
  $stmt=$db->query("SELECT id,starts_at,duration_minutes,source_time_snapshot,source_time_range_snapshot FROM appointments WHERE id IN (1,2,3,4,5,6,272,274) ORDER BY id");
 }
 foreach($stmt as $r) echo json_encode($r,JSON_UNESCAPED_SLASHES)."\n";
}
