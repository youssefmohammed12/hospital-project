<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
$pdo=getDB();
if(!preg_match('/^(127\.0\.0\.1|localhost)(\s|$)/i',(string)$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS))) exit(2);
if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!=='healthbridge') exit(3);
$targets=['success'=>'healthbridge_migration_ledger_test_success_20261120'];
$key=$argv[1]??'';if(!isset($targets[$key]))exit(4);
$target=$targets[$key];$exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$target]);if((int)$exists->fetchColumn()!==1)exit(5);
$pdo->exec('USE `'.$target.'`');$lockName=substr('hb-ledger-'.$target,0,64);$s=$pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lockName]);if((int)$s->fetchColumn()!==1)exit(6);
echo "LOCK_HELD\n";flush();sleep(max(1,min(15,(int)($argv[2]??6))));$r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);echo "LOCK_RELEASED\n";