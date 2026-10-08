<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;

/** Administrators of this training app. Account 1 is the primary administrator and alone manages the others. */
final class AdminAccounts
{
    public const PRIMARY_ID=1;
    public const SETUP_DAYS=7;
    public function __construct(private Store $store) {}
    public function all(): array { return $this->store->rows('SELECT id,email,name,password_hash IS NOT NULL AS active,setup_expires,created_at FROM accounts ORDER BY id'); }
    public function byId(int $id): ?array { return $this->store->one('SELECT * FROM accounts WHERE id=?',[$id]); }
    public function byEmail(string $email): ?array { return $this->store->one('SELECT * FROM accounts WHERE email=?',[strtolower(trim($email))]); }
    public function pendingSetup(): bool { return $this->store->one('SELECT id FROM accounts WHERE password_hash IS NULL AND setup_hash IS NOT NULL AND setup_expires>=? LIMIT 1',[time()])!==null; }
    private static function code(): string { return implode('-',str_split(strtoupper(bin2hex(random_bytes(10))),5)); }
    /** Accepts the code as shown, also typed in lower case or without dashes. Older 48-hex codes stay exact. */
    public static function setupCodeMatches(?string $hash,string $code): bool {
        if(!$hash)return false;
        $code=trim($code);$compact=strtoupper(preg_replace('/[\s-]+/','',$code));
        $candidates=[$code];
        if(preg_match('/^[0-9A-F]{20}$/',$compact))$candidates[]=implode('-',str_split($compact,5));
        foreach($candidates as $candidate)if(hash_equals($hash,hash('sha256',$candidate)))return true;
        return false;
    }
    private function requirePrimary(int $actorId): void {
        if($actorId!==self::PRIMARY_ID)throw new RuntimeException('Weitere Administratoren kann nur der Hauptadministrator verwalten.');
    }
    /** Returns the one-time setup code; only its hash is stored. */
    public function create(int $actorId,string $email,string $name): string {
        $this->requirePrimary($actorId);
        $email=strtolower(trim($email));$name=trim($name);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>254)throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
        if($name===''||mb_strlen($name)>100)throw new RuntimeException('Bitte einen Namen angeben (höchstens 100 Zeichen).');
        $code=self::code();
        $this->store->transaction(function()use($email,$name,$code){
            if($this->byEmail($email))throw new RuntimeException('Für diese E-Mail-Adresse besteht bereits ein Zugang.');
            $this->store->run('INSERT INTO accounts(email,name,setup_hash,setup_expires) VALUES(?,?,?,?)',[$email,$name,hash('sha256',$code),time()+self::SETUP_DAYS*86400]);
            $this->store->audit('admin_created','account',(int)$this->store->db->lastInsertId());
        });
        return $code;
    }
    /** Invalidates password and sessions of another administrator and returns a new one-time code. */
    public function resetSetup(int $actorId,int $id): string {
        $this->requirePrimary($actorId);
        if($id===self::PRIMARY_ID)throw new RuntimeException('Der eigene Zugang wird über „Passwort ändern“ gepflegt.');
        $code=self::code();
        $this->store->transaction(function()use($id,$code){
            if(!$this->store->run('UPDATE accounts SET password_hash=NULL,setup_hash=?,setup_expires=?,version=version+1 WHERE id=?',[hash('sha256',$code),time()+self::SETUP_DAYS*86400,$id]))throw new RuntimeException('Administrator nicht gefunden.');
            $this->store->audit('admin_setup_reset','account',$id);
        });
        return $code;
    }
    public function remove(int $actorId,int $id): void {
        $this->requirePrimary($actorId);
        if($id===self::PRIMARY_ID)throw new RuntimeException('Der Hauptadministrator kann nicht entfernt werden.');
        $this->store->transaction(function()use($id){
            if(!$this->store->run('DELETE FROM accounts WHERE id=?',[$id]))throw new RuntimeException('Administrator nicht gefunden.');
            $this->store->audit('admin_removed','account',$id);
        });
    }
}
