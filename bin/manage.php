<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/Schedule.php';
umask(0077);
$dir=getenv('TRAINING_DATA_DIR')?:__DIR__.'/../private';
if(!is_dir($dir)) mkdir($dir,0700,true);
$store=new Ausbildung\Store($dir.'/training.sqlite');
$store->schema();
if($store->normalizedDepartments||$store->duplicateNamesAfterNormalization)echo 'Feuerwehr-Schreibweisen korrigiert: '.$store->normalizedDepartments.'. Mögliche doppelte Personen: '.$store->duplicateNamesAfterNormalization.".\n";
$command=$argv[1]??'help';
if($command==='init') {
    if(!$store->one('SELECT id FROM accounts WHERE id=1')) {
        $code=bin2hex(random_bytes(24));
        $store->run('INSERT INTO accounts(id,email,setup_hash,setup_expires) VALUES(1,?,?,?)',['sascha.mathis@ffvgs.de',hash('sha256',$code),time()+7*86400]);
        file_put_contents($dir.'/setup-code.txt',$code."\n"); chmod($dir.'/setup-code.txt',0600);
        echo "Admin vorbereitet. Einmalcode liegt privat in setup-code.txt (7 Tage gültig).\n";
    } else echo "Zugang bereits vorhanden.\n";
} elseif($command==='import') {
    $data=json_decode(file_get_contents($argv[2]??''),true,512,JSON_THROW_ON_ERROR);
    echo json_encode($store->import($data),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
} elseif($command==='import-schedule') {
    // Only fills an empty year; existing lessons on the same dates are adopted with their attendance.
    $data=json_decode(file_get_contents($argv[2]??''),true,512,JSON_THROW_ON_ERROR);
    if(!is_int($data['year']??null)||!is_array($data['entries']??null))throw new RuntimeException('Unbekanntes Terminplanformat.');
    $result=(new Ausbildung\Schedule($store))->import($data['year'],$data['entries']);
    if($result['skipped'])echo 'Terminplan '.$data['year']." ist bereits vorhanden, nichts importiert.\n";
    else{
        $linked=count(array_filter($result['entries'],fn($e)=>$e['existing_lessons']>0));
        $created=array_sum(array_map(fn($e)=>max(0,$e['units']-$e['existing_lessons']),$result['entries']));
        echo 'Terminplan '.$data['year'].': '.count($result['entries']).' Termine übernommen, '.$linked.' davon mit vorhandenen Terminen verknüpft (Anwesenheiten bleiben erhalten), '.$created." Ausbildungseinheit(en) neu angelegt.\n";
        foreach($result['entries'] as $e)if($e['existing_lessons']>0&&$e['existing_lessons']<$e['units'])echo '  '.$e['date'].': laut Plan '.$e['units'].' Einheiten, bisher '.$e['existing_lessons'].". Die zusätzliche Einheit hat noch keine Anwesenheit.\n";
    }
} elseif($command==='reset-access') {
    $code=bin2hex(random_bytes(24));
    $store->run('UPDATE accounts SET password_hash=NULL,setup_hash=?,setup_expires=?,version=version+1 WHERE id=1',[hash('sha256',$code),time()+86400]);
    file_put_contents($dir.'/setup-code.txt',$code."\n"); chmod($dir.'/setup-code.txt',0600);
    $store->audit('access_reset'); echo "Einmalcode erneuert; bestehende Sitzungen ungültig.\n";
} else echo "init | import <private.json> | import-schedule <terminplan.json> | reset-access\n";
