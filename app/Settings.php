<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;

/** Key/value settings of the Einstellungen tab (Terminbuchung, E-Mail). Secrets are stored hashed or encrypted. */
final class Settings
{
    public const DEFAULTS=[
        'booking_enabled'=>'0','booking_code_hash'=>'','booking_code_version'=>'1','booking_code_required'=>'1','booking_list_needs_code'=>'0',
        'booking_close_hours'=>'0','booking_cancel_hours'=>'0','booking_max_per_email'=>'3','booking_double_optin'=>'0','booking_optin_minutes'=>'30',
        'booking_department_mode'=>'list','booking_notify_enabled'=>'0','booking_notify_email'=>'',
        'booking_rate_per_hour'=>'10','booking_rate_codes'=>'10','booking_retention_days'=>'90',
        'booking_confirm_text'=>'',
        'booking_privacy_text'=>'Deine Angaben (Name, Feuerwehr, E-Mail-Adresse) werden ausschließlich zur Organisation dieses Termins verwendet und nach Ablauf der Löschfrist automatisch gelöscht.',
        'mail_from_name'=>'Ausbildung VG Selters','mail_from'=>'','mail_reply_to'=>'',
        'smtp_host'=>'','smtp_port'=>'587','smtp_security'=>'starttls','smtp_user'=>'','smtp_password_enc'=>'',
        'mail_signature'=>"Viele Grüße\nAusbildung Feuerwehr VG Selters",
        'version'=>'1',
    ];
    private ?array $cache=null;
    public function __construct(private Store $store) {}
    public function all(): array {
        if($this->cache===null){
            $this->cache=self::DEFAULTS;
            foreach($this->store->rows('SELECT key,value FROM app_settings')as$row)if(array_key_exists($row['key'],self::DEFAULTS))$this->cache[$row['key']]=$row['value'];
        }
        return $this->cache;
    }
    public function get(string $key): string { return $this->all()[$key]??throw new RuntimeException('Unbekannte Einstellung.'); }
    public function int(string $key): int { return (int)$this->get($key); }
    public function on(string $key): bool { return $this->get($key)==='1'; }
    /** Saves several values at once; the version guards against two admins overwriting each other. */
    public function save(array $values,int $version,string $event): void {
        $this->store->transaction(function()use($values,$version,$event){
            $this->cache=null;
            if((int)$this->get('version')!==$version)throw new RuntimeException('Einstellungen inzwischen geändert. Bitte neu laden.');
            foreach($values as $key=>$value){
                if(!array_key_exists($key,self::DEFAULTS)||$key==='version')throw new RuntimeException('Unbekannte Einstellung.');
                $this->store->run('INSERT INTO app_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,(string)$value]);
            }
            $this->store->run("INSERT INTO app_settings(key,value) VALUES('version',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)($version+1)]);
            // Only the names of changed settings are logged, never values (codes, passwords).
            $this->store->audit($event,'settings',null,['keys'=>array_keys($values)]);
            $this->cache=null;
        });
    }
    public static function codeHash(string $code): string { return password_hash(hash('sha256',$code),PASSWORD_DEFAULT); }
    public static function codeMatches(string $hash,string $code): bool { return $hash!==''&&$code!==''&&password_verify(hash('sha256',$code),$hash); }
}
