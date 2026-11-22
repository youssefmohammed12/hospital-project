<?php
declare(strict_types=1);

/** Read-only logical backup/restore equivalence verifier for two isolated v2 databases. */
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';

const SOURCE_GUARD = 'healthbridge_hb_auth_email_verify_20261114';
const V2_SOURCE = 'healthbridge_v2_prototype_20261118';
const V2_RESTORE = 'healthbridge_v2_restore5_20261118';

function qi(string $name): string {
    $tick=chr(96);
    return $tick.str_replace($tick,$tick.$tick,$name).$tick;
}
function jsonHash(array $rows): string {
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function fetchRows(PDO $db,string $sql,array $params=[]): array {
    $q=$db->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function tableFingerprint(PDO $db,string $schema,string $table): array {
    $pk=fetchRows(
        $db,
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='PRIMARY'
         ORDER BY SEQ_IN_INDEX",
        [$schema,$table]
    );
    if(!$pk) throw new RuntimeException("No primary key on {$schema}.{$table}");
    $order=implode(',',array_map(static fn($r)=>qi($r['COLUMN_NAME']),$pk));
    $sql='SELECT * FROM '.qi($schema).'.'.qi($table).' ORDER BY '.$order;
    $stmt=$db->query($sql);
    $hash=hash_init('sha256');
    $count=0;
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach($row as $name=>$value) {
            hash_update($hash,(string)$name."\0");
            if($value===null) hash_update($hash,"N;");
            else {
                $bytes=(string)$value;
                hash_update($hash,"V".strlen($bytes).":".$bytes.";");
            }
        }
        hash_update($hash,"\n");
        $count++;
    }
    return ['rows'=>$count,'sha256'=>hash_final($hash)];
}

try {
    $db=getDB();
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==SOURCE_GUARD) {
        throw new RuntimeException('Wrong source guard schema.');
    }
    foreach([V2_SOURCE,V2_RESTORE] as $schema) {
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $q->execute([$schema]);
        if((int)$q->fetchColumn()!==1) throw new RuntimeException('Missing expected database '.$schema);
    }

    $tableLists=[];
    foreach([V2_SOURCE,V2_RESTORE] as $schema) {
        $tableLists[$schema]=$db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=" . $db->quote($schema) . " AND TABLE_TYPE='BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    if($tableLists[V2_SOURCE]!==$tableLists[V2_RESTORE]) {
        throw new RuntimeException('Backup/restore base-table name set differs.');
    }
    if(count($tableLists[V2_SOURCE])!==37) {
        throw new RuntimeException('Expected 37 base tables in v2 backup.');
    }

    $sourceMeta=fetchRows(
        $db,
        "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION,AUTO_INCREMENT
         FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'
         ORDER BY TABLE_NAME",
        [V2_SOURCE]
    );
    $restoreMeta=fetchRows(
        $db,
        "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION,AUTO_INCREMENT
         FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'
         ORDER BY TABLE_NAME",
        [V2_RESTORE]
    );
    if($sourceMeta!==$restoreMeta) throw new RuntimeException('Engine/collation/AUTO_INCREMENT metadata differs.');

    $sourceColumns=fetchRows(
        $db,
        "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,
                EXTRA,GENERATION_EXPRESSION
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION",
        [V2_SOURCE]
    );
    $restoreColumns=fetchRows(
        $db,
        "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,
                EXTRA,GENERATION_EXPRESSION
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION",
        [V2_RESTORE]
    );
    if($sourceColumns!==$restoreColumns) throw new RuntimeException('Column definition signature differs.');

    $sourceIndexes=fetchRows(
        $db,
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        [V2_SOURCE]
    );
    $restoreIndexes=fetchRows(
        $db,
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        [V2_RESTORE]
    );
    if($sourceIndexes!==$restoreIndexes) throw new RuntimeException('Index signature differs.');

    $sourceFKs=fetchRows(
        $db,
        "SELECT rc.TABLE_NAME,rc.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,rc.DELETE_RULE,rc.UPDATE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE k
           ON k.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND k.TABLE_NAME=rc.TABLE_NAME
          AND k.CONSTRAINT_NAME=rc.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA=? ORDER BY rc.TABLE_NAME,rc.CONSTRAINT_NAME,k.ORDINAL_POSITION",
        [V2_SOURCE]
    );
    $restoreFKs=fetchRows(
        $db,
        "SELECT rc.TABLE_NAME,rc.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,rc.DELETE_RULE,rc.UPDATE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE k
           ON k.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND k.TABLE_NAME=rc.TABLE_NAME
          AND k.CONSTRAINT_NAME=rc.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA=? ORDER BY rc.TABLE_NAME,rc.CONSTRAINT_NAME,k.ORDINAL_POSITION",
        [V2_RESTORE]
    );
    if($sourceFKs!==$restoreFKs) throw new RuntimeException('Foreign key mapping/rule signature differs.');

    $sourceChecks=fetchRows(
        $db,
        "SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE
         FROM information_schema.TABLE_CONSTRAINTS tc
         JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA=? AND tc.CONSTRAINT_TYPE='CHECK'
         ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME",
        [V2_SOURCE]
    );
    $restoreChecks=fetchRows(
        $db,
        "SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE
         FROM information_schema.TABLE_CONSTRAINTS tc
         JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA=? AND tc.CONSTRAINT_TYPE='CHECK'
         ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME",
        [V2_RESTORE]
    );
    if($sourceChecks!==$restoreChecks) throw new RuntimeException('CHECK constraint signature differs.');

    $sourceViews=fetchRows(
        $db,
        "SELECT TABLE_NAME,VIEW_DEFINITION,IS_UPDATABLE,DEFINER,SECURITY_TYPE
         FROM information_schema.VIEWS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME",
        [V2_SOURCE]
    );
    $restoreViews=fetchRows(
        $db,
        "SELECT TABLE_NAME,VIEW_DEFINITION,IS_UPDATABLE,DEFINER,SECURITY_TYPE
         FROM information_schema.VIEWS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME",
        [V2_RESTORE]
    );
    // MariaDB fully qualifies table references in VIEW_DEFINITION with the owning schema name.
    // Normalize only that expected database rename, then compare the remaining definition/security.
    if(count($sourceViews)!==count($restoreViews)) throw new RuntimeException('View count differs.');
    foreach($sourceViews as $index=>$sourceView) {
        $sourceView['VIEW_DEFINITION']=str_replace(V2_SOURCE,V2_RESTORE,$sourceView['VIEW_DEFINITION']);
        if($sourceView!==$restoreViews[$index]) {
            throw new RuntimeException('View definition/security differs after schema-name normalization.');
        }
    }

    $dataFailures=[];
    foreach($tableLists[V2_SOURCE] as $table) {
        $a=tableFingerprint($db,V2_SOURCE,$table);
        $b=tableFingerprint($db,V2_RESTORE,$table);
        if($a!==$b) $dataFailures[$table]=[$a,$b];
        else echo "TABLE_DATA|{$table}|{$a['rows']}|MATCH\n";
    }
    if($dataFailures) {
        foreach($dataFailures as $table=>$diff) echo "TABLE_DATA_DIFF|{$table}|".json_encode($diff)."\n";
        throw new RuntimeException('One or more table data fingerprints differ.');
    }

    $restoreFKCount=(int)$db->query(
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=" . $db->quote(V2_RESTORE)
    )->fetchColumn();
    if($restoreFKCount!==42) throw new RuntimeException('Restored FK count mismatch.');

    $checkFailures=[];
    foreach($tableLists[V2_RESTORE] as $table) {
        $rows=$db->query('CHECK TABLE '.qi(V2_RESTORE).'.'.qi($table))->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row) {
            if(($row['Msg_type']??'')==='Error' ||
               (($row['Msg_type']??'')==='status' && ($row['Msg_text']??'')!=='OK')) {
                $checkFailures[]=$table.':'.($row['Msg_text']??'unknown');
            }
        }
    }
    if($checkFailures) throw new RuntimeException('Restored CHECK TABLE failures: '.implode(',',$checkFailures));

    echo "V2_BACKUP_RESTORE_PASS\n";
    echo 'source=' . V2_SOURCE . "\n";
    echo 'restore=' . V2_RESTORE . "\n";
    echo 'base_tables=' . count($tableLists[V2_SOURCE]) . "\n";
    echo 'views=' . count($sourceViews) . "\n";
    echo 'columns=' . count($sourceColumns) . "\n";
    echo 'index_rows=' . count($sourceIndexes) . "\n";
    echo 'foreign_key_columns=' . count($sourceFKs) . "\n";
    echo 'check_constraints=' . count($sourceChecks) . "\n";
    echo "row_hashes=37/37\n";
    echo 'check_table=' . count($tableLists[V2_RESTORE]) . '/' . count($tableLists[V2_RESTORE]) . "\n";
    echo "auto_increment_and_engines=MATCH\n";
} catch(Throwable $error) {
    fwrite(STDERR,'V2_BACKUP_RESTORE_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
