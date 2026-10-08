<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/AdminAccounts.php';
use Ausbildung\{Store,AdminAccounts};
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/training-admins-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try{
    // Database as installed before multi-admin support.
    $db=new PDO('sqlite:'.$dir.'/training.sqlite');
    $db->exec('CREATE TABLE accounts(id INTEGER PRIMARY KEY CHECK(id=1),email TEXT NOT NULL UNIQUE,password_hash TEXT,setup_hash TEXT,setup_expires INTEGER,version INTEGER NOT NULL DEFAULT 1)');
    $hash=password_hash('Synthetic-test-password',PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO accounts(id,email,password_hash,version) VALUES(1,?,?,7)')->execute(['sascha.mathis@ffvgs.de',$hash]);
    unset($db);
    $s=new Store($dir.'/training.sqlite');$s->schema();$s->schema();
    $a=$s->one('SELECT * FROM accounts WHERE id=1');
    check($a['password_hash']===$hash&&(int)$a['version']===7&&$a['email']==='sascha.mathis@ffvgs.de','Primary admin kept unchanged');
    check(!str_contains((string)$s->one("SELECT sql FROM sqlite_master WHERE name='accounts'")['sql'],'CHECK(id=1)'),'Single-admin restriction removed');
    check((int)$s->one("SELECT COUNT(*) n FROM audit WHERE event='accounts_multi_admin_migrated'")['n']===1,'Migration runs once');
    check($s->one("SELECT name FROM sqlite_master WHERE name='documents'")!==null,'Documents table created');
    $admins=new AdminAccounts($s);
    $code=$admins->create(1,' Zweiter.Admin@Example.org ','Zweiter Admin');
    $b=$admins->byEmail('zweiter.admin@example.org');
    check($b!==null&&(int)$b['id']===2&&$b['password_hash']===null&&$b['setup_hash']===hash('sha256',$code),'Second admin pending with hashed code');
    check(!str_contains(json_encode($s->rows('SELECT * FROM audit')),$code),'Code not in audit');
    check(AdminAccounts::setupCodeMatches($b['setup_hash'],$code)&&AdminAccounts::setupCodeMatches($b['setup_hash'],strtolower(str_replace('-','',$code)))&&!AdminAccounts::setupCodeMatches($b['setup_hash'],'falsch'),'Code comparison');
    check($admins->pendingSetup(),'Pending setup detected');
    foreach([fn()=>$admins->create(1,'zweiter.admin@example.org','Doppelt'),fn()=>$admins->create(1,'keine-mail','X'),fn()=>$admins->create(2,'dritter@example.org','Dritter'),
        fn()=>$admins->remove(2,1),fn()=>$admins->remove(1,1),fn()=>$admins->resetSetup(1,1),fn()=>$admins->resetSetup(2,2)] as $i=>$denied){
        try{$denied();throw new LogicException('Denied action allowed: '.$i);}catch(RuntimeException $e){}
    }
    $s->run('UPDATE accounts SET password_hash=?,setup_hash=NULL WHERE id=2',[$hash]);
    $new=$admins->resetSetup(1,2);$b=$admins->byId(2);
    check($b['password_hash']===null&&(int)$b['version']===2&&AdminAccounts::setupCodeMatches($b['setup_hash'],$new),'Reset invalidates password and sessions');
    $admins->remove(1,2);check($admins->byId(2)===null&&count($admins->all())===1,'Second admin removed');
    echo "PASS: single-admin migration keeps credentials, add/reset/remove admins, primary-only management, code handling\n";
}finally{unset($s,$admins);foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
