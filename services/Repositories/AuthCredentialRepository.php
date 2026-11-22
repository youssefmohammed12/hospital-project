<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

/** Explicit v1/v2 credential lookup; never infers the production runtime mode. */
final class AuthCredentialRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1,self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('AuthCredentialRepository schema must be explicitly v1 or v2.');
        }
        $this->db=$db;
        $this->schema=$schema;
    }

    public function findLoginUser(string $email): ?array
    {
        $passwordColumn=$this->schema===self::SCHEMA_V1?'password':'password_hash';
        $stmt=$this->db->prepare(
            "SELECT id,name,email,{$passwordColumn} AS password_hash,role,is_active,session_version,
                    " . authEmailVerificationSelectColumns($this->db) . "
             FROM users WHERE email=? LIMIT 1"
        );
        $stmt->execute([$email]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }
}
