<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
$dir=sys_get_temp_dir().'/training-promote-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$s=new Ausbildung\Store($dir.'/training.sqlite');$s->schema();
$hash=password_hash('Synthetic-test-password',PASSWORD_DEFAULT);
$s->run('INSERT INTO accounts(id,email,password_hash) VALUES(1,?,?)',['sascha.mathis@ffvgs.de',$hash]);
putenv('TRAINING_ENV=production');putenv('TRAINING_DATA_DIR='.$dir);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/promote.php');
try{
    exec($command,$output,$result);if($result!==0)throw new RuntimeException('Promotion failed');
    $a=$s->one('SELECT * FROM accounts WHERE id=1');
    if($a['password_hash']!==$hash||(int)$a['version']!==2||$a['setup_hash']!==null)throw new RuntimeException('Credentials changed');
    exec($command,$output,$result);
    if($result!==0||(int)$s->one('SELECT version FROM accounts')['version']!==2)throw new RuntimeException('Promotion not idempotent');
    $s->run('DELETE FROM audit');$s->run('UPDATE accounts SET password_hash=NULL');
    exec($command,$output,$result);
    $a=$s->one('SELECT * FROM accounts WHERE id=1');
    $code=trim(file_get_contents($dir.'/setup-code.txt'));
    if($result!==0||!hash_equals($a['setup_hash'],hash('sha256',$code)))throw new RuntimeException('Pending setup not initialized');
    echo "PASS: promotion preserves password, invalidates old sessions, is idempotent and handles pending setup\n";
}finally{unset($s);foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
