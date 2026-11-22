<?php
declare(strict_types=1);

/**
 * Dual-schema AuditService contract. Writes are enclosed in a transaction and
 * rolled back; AUTO_INCREMENT high-water values are restored after rollback.
 * Only the two explicitly approved local disposable fixtures are accepted.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const HB_AUDIT_FIXTURES = [
    'v1' => 'healthbridge_hb_auth_email_verify_20261114',
    'v2' => 'healthbridge_v2_prototype_20261118',
];

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$passValue = getenv('DB_PASS');
$password = $passValue === false ? '' : (string)$passValue;
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Non-loopback DB host refused.\n");
    exit(11);
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "Invalid DB port.\n");
    exit(12);
}

require_once __DIR__ . '/../services/AdminAuditService.php';

$assertions = 0;
function auditAssert(bool $condition, string $label): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException('FAIL|' . $label);
    echo "PASS|{$label}\n";
}

function connectAuditFixture(string $dbName): PDO {
    $db = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";port=" . (getenv('DB_PORT') ?: '3306') . ";dbname={$dbName};charset=utf8mb4",
        (string)(getenv('DB_USER') ?: 'root'),
        (($v = getenv('DB_PASS')) === false ? '' : (string)$v),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $dbName) {
        throw new RuntimeException('Audit fixture identity mismatch.');
    }
    return $db;
}

function snapshotAuditAutoIncrement(PDO $db, string $table): ?int {
    if (!preg_match('/^[a-z_]+$/', $table)) throw new RuntimeException('Invalid table name.');
    $stmt=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    $value=$stmt->fetchColumn();
    return $value===false||$value===null?null:(int)$value;
}

function restoreAuditAutoIncrement(PDO $db,string $table,?int $value):void {
    if($value===null)return;
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1)throw new RuntimeException('Invalid AUTO_INCREMENT metadata.');
    $db->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.(int)$value);
}

function runAuditFixture(string $schema,string $dbName):void {
    $table=$schema==='v2'?'audit_events':'admin_audit';
    $db=connectAuditFixture($dbName);
    $auto=snapshotAuditAutoIncrement($db,$table);
    $db->beginTransaction();
    try {
        $admin=(int)$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
        if($admin<=0)throw new RuntimeException('No active fixture admin.');
        $invalidRejected=false;
        try { new AuditService($db,$admin,'admin','v3'); }
        catch(InvalidArgumentException $expected) { $invalidRejected=true; }
        auditAssert($invalidRejected,$schema.' unsupported schema rejected');

        $service=new AdminAuditService($db,$admin,$schema);
        auditAssert($service->log(
            'schema_adapter_test','appointment',987654,
            ['status'=>'before','password'=>'should be stripped'],
            ['status'=>'after'],
            'Audit adapter HTTP compatibility test',null,null
        ),$schema.' write target');
        $result=$service->getAuditLog([
            'action'=>'schema_adapter_test','entity_type'=>'appointment',
            'entity_id'=>987654,'actor_id'=>$admin,'actor_role'=>'admin',
            'search'=>'Audit adapter HTTP compatibility test','page'=>1,'limit'=>10
        ]);
        auditAssert(($result['total']??0)===1,$schema.' filtered read count');
        $entry=$result['entries'][0]??[];
        auditAssert((int)($entry['actor_id']??0)===$admin,$schema.' actor ID DTO alias');
        auditAssert(($entry['actor_role']??'')==='admin',$schema.' actor role DTO alias');
        auditAssert(($entry['action']??'')==='schema_adapter_test',$schema.' action DTO alias');
        auditAssert((string)($entry['entity_id']??'')==='987654',$schema.' entity ID DTO alias');
        auditAssert(isset($entry['created_at']) && !empty($entry['actor_name']),$schema.' timestamp/name DTO aliases');
        auditAssert(in_array('schema_adapter_test',$service->getDistinctActions(),true),$schema.' distinct actions');
        auditAssert(in_array('appointment',$service->getDistinctEntityTypes(),true),$schema.' distinct entity types');
        $encoded=(string)($entry['old_value']??'');
        auditAssert(!str_contains($encoded,'should be stripped') && str_contains($encoded,'[REDACTED]'),$schema.' password redaction');
        $db->rollBack();
        restoreAuditAutoIncrement($db,$table,$auto);
        auditAssert((int)$db->query("SELECT COUNT(*) FROM {$table} WHERE " .
            ($schema==='v2'?"action_code":"action") . "='schema_adapter_test'")->fetchColumn()===0,$schema.' rollback verified');
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        try { restoreAuditAutoIncrement($db,$table,$auto); } catch(Throwable $restore) {
            throw new RuntimeException($e->getMessage().'; restore failed: '.$restore->getMessage(),0,$e);
        }
        throw $e;
    }
}

try {
    $reflection=new ReflectionClass(AuditService::class);
    auditAssert($reflection->getConstructor()->getNumberOfParameters()===4,'constructor retains defaulted V1 fourth parameter');
    runAuditFixture('v1',HB_AUDIT_FIXTURES['v1']);
    runAuditFixture('v2',HB_AUDIT_FIXTURES['v2']);
    echo "RESULT=PASS|assertions={$assertions}\n";
} catch(Throwable $e) {
    fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");
    exit(1);
}
