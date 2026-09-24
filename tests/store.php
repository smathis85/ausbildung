<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
use Ausbildung\Store;
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/training-tests-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$s=new Store($dir.'/test.sqlite');$s->schema();
$fixture=['format_version'=>1,'source_sha256'=>str_repeat('a',64),
 'people'=>[['source_key'=>'demo','last_name'=>'Beispiel','first_name'=>'Alex','department'=>'Testwehr']],
 'sessions'=>[['source_key'=>'2025:7','year'=>2025,'date'=>'2025-04-01','unit'=>1,'title'=>'Testausbildung','source_label'=>'01.04.2025'],['source_key'=>'2026:7','year'=>2026,'date'=>'2026-04-01','unit'=>1,'title'=>'Testausbildung','source_label'=>'01.04.2026'],['source_key'=>'2026:8','year'=>2026,'date'=>'2026-04-01','unit'=>2,'title'=>'Testausbildung','source_label'=>'01.04.2026']],
 'enrollments'=>[], 'attendance'=>[['person'=>'demo','session'=>'2025:7'],['person'=>'demo','session'=>'2026:7']], 'warnings'=>[], 'summary'=>[]];
foreach([2025,2026] as $y)$fixture['enrollments'][]=['person'=>'demo','year'=>$y,'adjustment'=>$y===2025?10:0,'source_carry'=>$y===2025?10:11,'source_total'=>$y===2025?11:12,'start_label'=>'','start_date'=>null,'course'=>'','exam_date'=>null,'exam_label'=>'','reported'=>'','comment'=>'','adjustment_reason'=>'Testvortrag'];
try{
 $s->import($fixture);check($s->total(1,2025)===11,'2025 total');check($s->total(1,2026)===12,'2026 total without duplicate carry');
 check($s->import($fixture)===['duplicate'=>true],'Idempotent import');
 $s->saveAttendance(3,1,[1]);check($s->total(1,2026)===13,'Separate units count');
 $s->saveAttendance(3,2,[1]);check($s->total(1,2026)===13,'Repeat attendance unchanged');
 try{$s->saveAttendance(3,2,[]);throw new LogicException('Stale write allowed');}catch(RuntimeException $e){}
 check($s->total(1,2026)===13,'Stale write rolled back');
 try{$s->saveAttendance(3,3,[999]);throw new LogicException('Invalid person allowed');}catch(RuntimeException $e){}
 check($s->total(1,2026)===13,'Invalid write rolled back');
 $s->saveAttendance(1,1,[]);check($s->total(1,2025)===10&&$s->total(1,2026)===12,'History corrections propagate');
 $s->saveAttendance(2,1,[]);check($s->total(1,2026)===11,'Below threshold');
 check($s->participants(2026)[0]['total']===11,'Dashboard and detail agree');
 check((int)$s->one('SELECT COUNT(*) n FROM audit')['n']===6,'Audit covers committed changes only');
 echo "PASS: import, totals, threshold, units, idempotency, conflicts, rollback, history and audit\n";
}finally{unset($s);unlink($dir.'/test.sqlite');rmdir($dir);}
