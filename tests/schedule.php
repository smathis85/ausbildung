<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/Schedule.php';
use Ausbildung\Schedule;
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function fails(callable $fn,string $message):void {try{$fn();}catch(RuntimeException $e){return;}throw new LogicException($message);}
$dir=sys_get_temp_dir().'/training-schedule-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$s=new Ausbildung\Store($dir.'/test.sqlite');$s->schema();$s->schema();
$plan=new Schedule($s);
try{
 // Synthetic data only.
 $s->run("INSERT INTO participants(id,first_name,last_name,department) VALUES(1,'Alex','Beispiel','Testwehr')");
 $s->run('INSERT INTO enrollments(participant_id,year) VALUES(1,2026)');
 $s->run("INSERT INTO lessons(id,year,date,unit,title) VALUES(1,2026,'2026-04-25',1,'Ausbildung')");
 $s->saveAttendance(1,1,[1]);
 $base=['time_text'=>"8.00 Uhr\r\nbis\r\n14.30 Uhr",'topic'=>'Löscheinsatz','content'=>'Teil 1','vehicles'=>'TLF Test','instructors'=>"Muster, Max\nTest, Tina",'notes'=>'Ort: Testhaus'];
 // Saturday: two units; the existing lesson with attendance is adopted, a second is created.
 $id=$plan->save(0,0,$base+['date'=>'2026-04-25','units'=>0]);
 $lessons=$plan->lessons($id);
 check(count($lessons)===2&&(int)$lessons[0]['id']===1&&(int)$lessons[0]['count']===1,'Saturday adopts lesson and adds unit 2');
 check($lessons[0]['title']==='Löscheinsatz'&&(int)$lessons[1]['unit']===2,'Lesson titles and units follow the plan');
 check($plan->find($id)['time_text']==="8.00 Uhr\nbis\n14.30 Uhr",'Line breaks normalised');
 check($s->total(1,2026)===1,'Attendance unchanged');
 // Date change moves both lessons; a weekday then means one unit, the empty unit 2 is removed.
 $plan->save($id,1,$base+['date'=>'2026-04-30','units'=>0]);
 $lessons=$plan->lessons($id);
 check(count($lessons)===1&&$lessons[0]['date']==='2026-04-30'&&(int)$lessons[0]['id']===1,'Date change and unit reduction');
 // Units with attendance are never dropped.
 $plan->save($id,2,$base+['date'=>'2026-04-30','units'=>2]);
 $unit2=(int)$plan->lessons($id)[1]['id'];$s->saveAttendance($unit2,(int)$s->one('SELECT version FROM lessons WHERE id=?',[$unit2])['version'],[1]);
 fails(fn()=>$plan->save($id,3,$base+['date'=>'2026-04-30','units'=>1]),'Unit with attendance dropped');
 check(count($plan->lessons($id))===2&&$s->total(1,2026)===2,'Failed save rolled back');
 fails(fn()=>$plan->save($id,1,$base+['date'=>'2026-04-30','units'=>2]),'Stale version accepted');
 fails(fn()=>$plan->save($id,3,$base+['date'=>'2027-01-07','units'=>0]),'Year change accepted');
 fails(fn()=>$plan->save(0,0,['topic'=>'','date'=>'2026-05-01']),'Empty topic accepted');
 fails(fn()=>$plan->save(0,0,['topic'=>'X','date'=>'2026-02-30']),'Invalid date accepted');
 $thursday=$plan->save(0,0,['topic'=>'Gefahrstoffe','date'=>'2026-10-08']);
 check(count($plan->lessons($thursday))===1,'Weekday is one unit');
 // Copying keeps weekdays and starts as an unpublished draft.
 check($plan->copyYear(2026,2027)===2,'Copy count');
 $copied=$plan->entries(2027);
 check($copied[0]['date']==='2027-04-29'&&Schedule::weekday($copied[0]['date'])==='Donnerstag','Same weekday next year');
 check($copied[1]['date']==='2027-10-07'&&$copied[0]['instructors']==="Muster, Max\nTest, Tina",'Content copied');
 check(count($plan->lessons((int)$copied[0]['id']))===2,'Copied units create lessons');
 check($plan->years(true)===[2026]&&$plan->years(false)===[2027,2026],'Copied year is a draft');
 fails(fn()=>$plan->copyYear(2026,2027),'Second copy accepted');
 $plan->saveSettings(2027,1,'Titel','Zusatz','Fuß',true);
 check($plan->years(true)===[2027,2026],'Released');
 fails(fn()=>$plan->saveSettings(2027,1,'Titel','','',true),'Stale settings accepted');
 // Deleting keeps lessons that have attendance.
 check($plan->delete($id)===2&&(int)$s->one('SELECT COUNT(*) n FROM lessons WHERE schedule_id IS NULL')['n']===2,'Delete keeps attended lessons');
 check($s->total(1,2026)===2,'History intact after delete');
 // Import only into an empty year.
 $import=$plan->import(2028,[['date'=>'2028-01-08','topic'=>'Samstag'],['date'=>'2028-01-13','topic'=>'Donnerstag']]);
 check(!$import['skipped']&&$import['entries'][0]['units']===2,'Import');
 check($plan->import(2028,[['date'=>'2028-01-20','topic'=>'X']])===['skipped'=>true],'Import idempotent');
 echo "PASS: schedule units, lesson sync, adoption, conflicts, copy, release, delete and import\n";
}finally{unset($s,$plan);unlink($dir.'/test.sqlite');rmdir($dir);}
