<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';require __DIR__.'/../app/ParticipantCsv.php';
use Ausbildung\Store;use Ausbildung\ParticipantCsv;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$s=new Store(':memory:');$s->schema();$import=new ParticipantCsv($s);
$csv="\xEF\xBB\xBFVorname;Nachname;Feuerwehr;Ausbildungsbeginn;Lehrgang\r\nAlex;Müller;Testwehr;24.09.2026;\"Gruppe; 1\"\r\n alex ;MÜLLER;testwehr;;\r\nAlex;Müller;Andere Wehr;;\r\n";
$rows=ParticipantCsv::parse($csv);$preview=$import->preview($rows);
check(count($preview)===3&&!$preview[0]['duplicate']&&$preview[1]['duplicate']&&!$preview[2]['duplicate'],'Duplicate preview');
check($rows[0]['start_date']==='2026-09-24'&&$rows[0]['course']==='Gruppe; 1','CSV quoting and date');
check((int)$s->one('SELECT COUNT(*) n FROM participants')['n']===0,'Preview never writes');
check($import->apply($rows,2026)===['added'=>2,'skipped'=>1],'Apply counts');
check($import->apply($rows,2026)===['added'=>0,'skipped'=>3],'Repeated import idempotent');
check($s->total(1,2026)===0,'No invented attendance');
check(count(ParticipantCsv::parse("Feuerwehr,Nachname,Vorname\nTestwehr,Beispiel,Kim\n"))===1,'Comma and alternate column order');
foreach(["Vorname;Nachname\nA;B", "Vorname;Nachname;Feuerwehr\nA;;C", "Vorname;Nachname;Feuerwehr;Ausbildungsbeginn\nA;B;C;31.02.2026", "Vorname;Vorname;Feuerwehr\nA;B;C", "Vorname;Nachname;Feuerwehr;Unbekannt\nA;B;C;x", "Vorname;Nachname;Feuerwehr\n", str_repeat('x',524289)] as $bad){
 try{ParticipantCsv::parse($bad);throw new LogicException('Invalid CSV accepted');}catch(RuntimeException $e){}
}
// A database failure rolls back the entire file.
$more=ParticipantCsv::parse("Vorname;Nachname;Feuerwehr\nFirst;Valid;Testwehr\nSecond;Reject;Testwehr");
$s->db->exec("CREATE TRIGGER reject_test BEFORE INSERT ON participants WHEN NEW.last_name='Reject' BEGIN SELECT RAISE(ABORT,'test rollback'); END");
try{$import->apply($more,2026);throw new LogicException('Failure expected');}catch(PDOException $e){}
check((int)$s->one('SELECT COUNT(*) n FROM participants')['n']===2,'Whole-file rollback');
echo "PASS: CSV parser, validation, duplicate handling, preview, repeated import and rollback\n";
