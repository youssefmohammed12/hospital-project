<?php
declare(strict_types=1);

/**
 * Password reset token storage and consumption (V2 schema).
 * Raw tokens are returned only to the caller for one-time mail delivery.
 */
final class PasswordResetRepository
{
    private PDO $db;
    private string $table = 'password_reset_tokens';
    private string $passwordColumn = 'password_hash';

    public function __construct(PDO $db)
    {
        $this->db=$db;
    }

    /**
     * Create a reset attempt with the existing per-email/IP limits.
     * Unknown or inactive emails receive an undisclosed random hash marked
     * used (token_hash is NOT NULL in V2).
     *
     * @return array{rate_limited:bool,token:?string,request_id:?int}
     */
    public function createRequest(string $email,?string $requestIp):array
    {
        $this->db->beginTransaction();
        try{
            $this->db->exec(
                "DELETE FROM {$this->table}
                 WHERE created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)"
            );

            $lock=$this->db->prepare("SELECT id FROM {$this->table} WHERE email=? FOR UPDATE");
            $lock->execute([$email]);
            $lock->fetchAll();

            $emailCount=$this->db->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE email=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)"
            );
            $emailCount->execute([$email]);
            $emailAttempts=(int)$emailCount->fetchColumn();

            $ipAttempts=0;
            if($requestIp!==null){
                $ipCount=$this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->table}
                     WHERE request_ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)"
                );
                $ipCount->execute([$requestIp]);
                $ipAttempts=(int)$ipCount->fetchColumn();
            }

            if($emailAttempts>=3||$ipAttempts>=5){
                $this->db->rollBack();
                return ['rate_limited'=>true,'token'=>null,'request_id'=>null];
            }

            // Serialize requests for an existing account as well as existing
            // token rows. Reset completion follows the same token-row -> user-row
            // lock order, avoiding request/consume lock inversion.
            $userStmt=$this->db->prepare(
                'SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1 FOR UPDATE'
            );
            $userStmt->execute([$email]);
            $activeUser=(bool)$userStmt->fetchColumn();

            $this->db->prepare(
                "UPDATE {$this->table} SET used=1 WHERE email=? AND used=0"
            )->execute([$email]);

            $token=$activeUser?bin2hex(random_bytes(32)):null;
            $tokenHash=$token!==null?hash('sha256',$token):null;
            $used=$activeUser?0:1;

            // V2 requires token_hash NOT NULL. An unknown/inactive address gets
            // an unguessable, undisclosed value already marked used.
            $storedHash=$tokenHash??hash('sha256',random_bytes(32));
            $insert=$this->db->prepare(
                "INSERT INTO password_reset_tokens
                    (email,token_hash,request_ip,expires_at,used,created_at)
                 VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE),?,NOW())"
            );
            $insert->execute([$email,$storedHash,$requestIp,$used]);

            $id=(int)$this->db->lastInsertId();
            $this->db->commit();
            return ['rate_limited'=>false,'token'=>$token,'request_id'=>$id];
        }catch(Throwable $error){
            if($this->db->inTransaction()){$this->db->rollBack();}
            throw $error;
        }
    }

    public function invalidateUndeliveredToken(int $requestId):void
    {
        if($requestId<=0){return;}
        $this->db->prepare(
            'UPDATE password_reset_tokens SET used=1 WHERE id=? AND used=0'
        )->execute([$requestId]);
    }

    public function isTokenValid(string $tokenHash):bool
    {
        $stmt=$this->db->prepare(
            "SELECT id FROM {$this->table}
             WHERE token_hash=? AND used=0 AND expires_at>NOW() LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Consume this token and all outstanding tokens for its email while changing
     * credentials and revoking every existing session in a single transaction.
     */
    public function resetPassword(string $tokenHash,string $passwordHash):void
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$tokenHash)
            || $passwordHash===''||mb_strlen($passwordHash)>255){
            throw new InvalidArgumentException('A valid reset token and password hash are required.');
        }

        $this->db->beginTransaction();
        try{
            $tokenStmt=$this->db->prepare(
                "SELECT id,email FROM {$this->table}
                 WHERE token_hash=? AND used=0 AND expires_at>NOW()
                 LIMIT 1 FOR UPDATE"
            );
            $tokenStmt->execute([$tokenHash]);
            $reset=$tokenStmt->fetch(PDO::FETCH_ASSOC);
            if(!$reset){throw new DomainException('This reset link is invalid or has expired. Please request a new one.');}

            $userStmt=$this->db->prepare(
                "SELECT id,session_version FROM users
                 WHERE email=? AND is_active=1 LIMIT 1 FOR UPDATE"
            );
            $userStmt->execute([$reset['email']]);
            $user=$userStmt->fetch(PDO::FETCH_ASSOC);
            if(!$user){throw new DomainException('This reset link is invalid or has expired. Please request a new one.');}

            $update=$this->db->prepare(
                "UPDATE users SET {$this->passwordColumn}=?,session_version=session_version+1,updated_at=NOW()
                 WHERE id=? AND session_version=?"
            );
            $update->execute([$passwordHash,(int)$user['id'],(int)$user['session_version']]);
            if($update->rowCount()!==1){
                throw new DomainException('Your credentials changed during this request. Please try again.');
            }

            $consume=$this->db->prepare(
                "UPDATE {$this->table} SET used=1 WHERE email=? AND used=0"
            );
            $consume->execute([$reset['email']]);
            $this->db->commit();
        }catch(Throwable $error){
            if($this->db->inTransaction()){$this->db->rollBack();}
            throw $error;
        }
    }
}
