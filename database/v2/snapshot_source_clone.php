<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';
const EXPECTED_SCHEMA='healthbridge_hb_auth_email_verify_20261114';
try {
  $db=getDB();
  if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==EXPECTED_SCHEMA) {
    throw new RuntimeException('Wrong schema; refusing source fingerprint.');
  }
  $tables=$db->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
  )->fetchAll(PDO::FETCH_COLUMN);
  $manifest=['schema'=>EXPECTED_SCHEMA,'server'=>$db->query('SELECT VERSION()')->fetchColumn(),'tables'=>[]];
  foreach($tables as $table) {
    $q=$db->prepare(
      "SELECT COLUMN_NAME FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY'
       ORDER BY SEQ_IN_INDEX"
    );
    $q->execute([$table]);
    $pk=$q->fetchAll(PDO::FETCH_COLUMN);
    if(!$pk) throw new RuntimeException('No primary key for fingerprint table '.$table);
    $order=implode(',',array_map(static fn($name)=>$name,$pk));
    $stmt=$db->query('SELECT * FROM '.$table.' ORDER BY '.$order);
    $hash=hash_init('sha256');
    $count=0;
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
      foreach($row as $name=>$value) {
        hash_update($hash,(string)$name."\0");
        if($value===null) {
          hash_update($hash,"N;");
        } else {
          $bytes=(string)$value;
          hash_update($hash,"V".strlen($bytes).":".$bytes.";");
        }
      }
      hash_update($hash,"\n");
      $count++;
    }
    $manifest['tables'][$table]=['rows'=>$count,'sha256'=>hash_final($hash)];
  }
  echo json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) {
  fwrite(STDERR,'SNAPSHOT_FAILED: '.$e->getMessage().PHP_EOL);
  exit(1);
}
