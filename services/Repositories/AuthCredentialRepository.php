<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

/** Credential lookup against the V2 users schema (password_hash). */
final class AuthCredentialRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db=$db;
    }

    public function findLoginUser(string $email): ?array
    {
        $stmt=$this->db->prepare(
            "SELECT id,name,email,password_hash,role,is_active,session_version,
                    " . authEmailVerificationSelectColumns($this->db) . "
             FROM users WHERE email=? LIMIT 1"
        );
        $stmt->execute([$email]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }
}
