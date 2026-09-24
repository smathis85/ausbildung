<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TRAINING_LOCAL_TEST')!=='1')exit(1);
require __DIR__.'/../app/Store.php';
$dir=getenv('TRAINING_DATA_DIR');if(!$dir)exit(1);
if(!is_dir($dir))mkdir($dir,0700,true);
$s=new Ausbildung\Store($dir.'/training.sqlite');$s->schema();
$s->run('INSERT INTO accounts(id,email,setup_hash,setup_expires) VALUES(1,?,?,?)',['sascha.mathis@ffvgs.de',hash('sha256','TEST-SETUP-ONLY'),time()+3600]);
$s->run('INSERT INTO participants(id,first_name,last_name,department) VALUES(1,?,?,?)',['Alex','Beispiel','Musterwehr']);
$s->run('INSERT INTO enrollments(participant_id,year,adjustment,adjustment_reason,course) VALUES(1,2026,11,?,?)',['Testdaten','GA Demo']);
$s->run('INSERT INTO lessons(id,year,date,unit,title) VALUES(1,2026,?,1,?)',['2026-09-24','Löschangriff · Testtermin']);
echo "Synthetic test data ready.\n";
