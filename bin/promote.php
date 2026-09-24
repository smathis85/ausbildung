<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TRAINING_ENV')!=='production')exit(1);
require __DIR__.'/../app/Store.php';
umask(0077);
$dir=getenv('TRAINING_DATA_DIR');if(!$dir)throw new RuntimeException('Datenverzeichnis fehlt.');
$s=new Ausbildung\Store($dir.'/training.sqlite');
$s->transaction(function()use($s,$dir){
    if($s->one("SELECT id FROM audit WHERE event='production_promoted'"))return;
    $a=$s->one('SELECT * FROM accounts WHERE id=1');
    if(!$a||$a['email']!=='sascha.mathis@ffvgs.de')throw new RuntimeException('Erwarteter Administrator fehlt.');
    if(!$a['password_hash']) {
        $code=bin2hex(random_bytes(24));
        file_put_contents($dir.'/setup-code.txt',$code."\n");chmod($dir.'/setup-code.txt',0600);
        $s->run('UPDATE accounts SET setup_hash=?,setup_expires=?,version=version+1 WHERE id=1',[hash('sha256',$code),time()+7*86400]);
    } else {
        $s->run('UPDATE accounts SET setup_hash=NULL,setup_expires=NULL,version=version+1 WHERE id=1');
    }
    $s->run('DELETE FROM rate_limits');
    $s->audit('production_promoted');
});
echo "Produktionsübernahme vorbereitet. Bestehendes Passwort erhalten; keine Sitzungen übernommen.\n";
