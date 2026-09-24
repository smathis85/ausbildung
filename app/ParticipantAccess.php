<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;

final class ParticipantAccess
{
    public function __construct(private Store $store) {}
    public function settings(): array { return $this->store->one('SELECT * FROM participant_access WHERE id=1')??['enabled'=>0,'version'=>0,'code_hash'=>null]; }
    public static function normalize(string $text): string {
        $text=\Normalizer::normalize($text,\Normalizer::FORM_C)?:$text;
        return mb_strtolower(preg_replace('/\s+/u',' ',trim($text)),'UTF-8');
    }
    public function resolve(string $first,string $last,string $code,string $department=''): ?int {
        $config=$this->settings();
        if(!$config['enabled']||!$config['code_hash']||!password_verify(hash('sha256',$code),$config['code_hash']))return null;
        $first=self::normalize($first);$last=self::normalize($last);$department=self::normalize($department);
        if($first===''||$last==='')return null;
        $matches=[];
        foreach($this->store->rows('SELECT id,first_name,last_name,department FROM participants WHERE archived=0')as$p){
            if(self::normalize($p['first_name'])===$first&&self::normalize($p['last_name'])===$last&&($department===''||self::normalize($p['department'])===$department))$matches[]=(int)$p['id'];
        }
        return count($matches)===1?$matches[0]:null;
    }
    public function configure(bool $enabled,string $code,int $version): void {
        if($code!==''&&(mb_strlen($code)<8||mb_strlen($code)>128))throw new RuntimeException('Der zentrale Code muss zwischen 8 und 128 Zeichen lang sein.');
        $this->store->transaction(function()use($enabled,$code,$version){
            $old=$this->settings();
            if((int)$old['version']!==$version)throw new RuntimeException('Einstellung inzwischen geändert. Bitte neu laden.');
            if($enabled&&$code===''&&!$old['code_hash'])throw new RuntimeException('Bitte zuerst einen zentralen Code festlegen.');
            $hash=$code!==''?password_hash(hash('sha256',$code),PASSWORD_DEFAULT):$old['code_hash'];
            $this->store->run('UPDATE participant_access SET enabled=?,code_hash=?,version=version+1 WHERE id=1',[$enabled?1:0,$hash]);
            $this->store->audit('participant_access_changed','participant_access',1,['enabled'=>$enabled,'code_changed'=>$code!=='']);
        });
    }
    public function sessionValid(int $id,int $version): bool {
        $c=$this->settings();
        return (bool)$c['enabled']&&(int)$c['version']===$version&&$this->store->one('SELECT id FROM participants WHERE id=? AND archived=0',[$id])!==null;
    }
}
