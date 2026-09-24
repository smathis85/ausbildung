<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
use Ausbildung\Store;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['x','19.04.2021','2023-06-03 00:00:00','ja'] as $text)check(Store::legacyReportedConfirmed($text),'Confirmed legacy value');
foreach(['','nein','noch offen','31.02.2026','2026-99-99'] as $text)check(!Store::legacyReportedConfirmed($text),'Unconfirmed legacy value');
$s=new Store(':memory:');$s->schema();
$id=0;
foreach([11,12,13] as $total)foreach([false,true] as $exam)foreach([0,1] as $reported){
    $id++;
    $s->run('INSERT INTO participants(id,first_name,last_name,department) VALUES(?,?,?,?)',[$id,'Test','Person','Testwehr']);
    $s->run('INSERT INTO enrollments(participant_id,year,adjustment,exam_label,reported_confirmed) VALUES(?,2026,?,?,?)',[$id,$total,$exam?'bestanden':'',$reported]);
    $s->transaction(fn()=>$s->archiveCompletedParticipants($id));
    check((bool)$s->one('SELECT archived FROM participants WHERE id=?',[$id])['archived']===($total>=12&&$exam&&$reported===1),'All three conditions required');
}
$before=$s->one("SELECT COUNT(*) n FROM audit WHERE event='participant_auto_archived'")['n'];
$s->transaction(fn()=>$s->archiveCompletedParticipants());
check($before===$s->one("SELECT COUNT(*) n FROM audit WHERE event='participant_auto_archived'")['n'],'Archive is idempotent');
// Participant 4 has 11 participations, passed exam and confirmed reporting.
$s->run("INSERT INTO lessons(id,year,date,title) VALUES(1,2026,'2026-09-24','Synthetic training')");
$s->saveAttendance(1,1,[4]);
check((int)$s->one('SELECT archived FROM participants WHERE id=4')['archived']===1,'Twelfth attendance triggers archive');
check($s->annual(4,2026)===1&&$s->total(4,2026)===12,'History preserved');
$s->saveAttendance(1,2,[]);
check((int)$s->one('SELECT archived FROM participants WHERE id=4')['archived']===1,'No silent unarchive');
// Test upgrade of a legacy database, preserving the original reporting text.
$m=new Store(':memory:');$m->schema();$m->db->exec('ALTER TABLE enrollments DROP COLUMN reported_confirmed');
$m->run("INSERT INTO participants(id,first_name,last_name,department) VALUES(1,'Legacy','Example','Testwehr')");
$m->run("INSERT INTO enrollments(participant_id,year,adjustment,exam_label,reported) VALUES(1,2026,12,'bestanden','19.04.2021')");
$m->schema();$m->schema();
check($m->one('SELECT reported FROM enrollments')['reported']==='19.04.2021','Original note preserved');
check((int)$m->one('SELECT archived FROM participants')['archived']===1,'Existing qualifying participant archived on upgrade');
check((int)$m->one("SELECT COUNT(*) n FROM audit WHERE event='participant_auto_archived'")['n']===1,'Migration idempotent');
echo "PASS: archive conditions, attendance trigger, legacy conversion, history, idempotency and migration\n";
