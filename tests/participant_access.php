<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';require __DIR__.'/../app/ParticipantAccess.php';
$s=new Ausbildung\Store(':memory:');$s->schema();$a=new Ausbildung\ParticipantAccess($s);
$s->run('INSERT INTO participants(id,first_name,last_name,department) VALUES(1,?,?,?)',['Nico','Gräf','Testwehr']);
if($a->resolve('Nico','Gräf','Test-Code-2026')!==null)throw new RuntimeException('Default should be disabled');
$a->configure(true,'Test-Code-2026',1);
if($a->resolve(' nico ','GRÄF','Test-Code-2026')!==1)throw new RuntimeException('Name normalization failed');
if($a->resolve('Nico','Graef','Test-Code-2026')!==null||$a->resolve('Nico','Gräf','wrong')!==null)throw new RuntimeException('Invalid login allowed');
$s->run('INSERT INTO participants(id,first_name,last_name,department) VALUES(2,?,?,?)',['Nico','Gräf','Andere Wehr']);
if($a->resolve('Nico','Gräf','Test-Code-2026')!==null)throw new RuntimeException('Ambiguous name allowed');
if($a->resolve('Nico','Gräf','Test-Code-2026','Testwehr')!==1)throw new RuntimeException('Department disambiguation failed');
if(!$a->sessionValid(1,2))throw new RuntimeException('Valid session denied');
$a->configure(true,'New-Code-2026',2);
if($a->sessionValid(1,2)||$a->resolve('Nico','Gräf','Test-Code-2026','Testwehr')!==null)throw new RuntimeException('Old code/session accepted');
$a->configure(false,'',3);
if($a->sessionValid(1,4)||$a->resolve('Nico','Gräf','New-Code-2026','Testwehr')!==null)throw new RuntimeException('Disabled access accepted');
if(str_contains(json_encode($s->rows('SELECT * FROM audit')),'New-Code-2026'))throw new RuntimeException('Code leaked to audit');
echo "PASS: central code, disabled default, normalization, ambiguous names, rotation, session revocation, no code audit leak\n";
