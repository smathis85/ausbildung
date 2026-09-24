<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/ParticipantOverview.php';
$s=new Ausbildung\Store(':memory:');$s->schema();
foreach([1,2]as$id){
    $s->run('INSERT INTO participants(id,first_name,last_name,department) VALUES(?,?,?,?)',[$id,'Test'.$id,'Beispiel','Testwehr']);
    $s->run('INSERT INTO enrollments(participant_id,year,adjustment,comment,reported) VALUES(?,2026,11,?,?)',[$id,'PRIVATE_ADMIN_NOTE','PRIVATE_ADMIN_REPORT']);
}
$s->run("INSERT INTO lessons(id,year,date,title) VALUES(1,2026,'2026-09-24','Testeinheit')");
$s->run('INSERT INTO attendance(participant_id,lesson_id) VALUES(1,1)');
$overview=new Ausbildung\ParticipantOverview($s);$one=$overview->forAuthenticatedParticipant(1);$two=$overview->forAuthenticatedParticipant(2);
if($one['years'][0]['total']!==12||!$one['years'][0]['eligible']||$two['years'][0]['total']!==11||!$two['years'][0]['eligible'])throw new RuntimeException('Threshold failed');
if(count($one['attendance'])!==1||count($two['attendance'])!==0)throw new RuntimeException('Participant isolation failed');
$json=json_encode($one);if(str_contains($json,'PRIVATE_ADMIN')||str_contains($json,'Test2'))throw new RuntimeException('Excess data exposed');
foreach([9=>false,10=>true,11=>true,12=>true] as $total=>$eligible){
    $s->run('UPDATE enrollments SET adjustment=? WHERE participant_id=2',[$total]);
    $row=$overview->forAuthenticatedParticipant(2)['years'][0];
    if($row['eligible']!==$eligible||$row['missing']!==max(0,10-$total))throw new RuntimeException('Eligibility boundary failed');
}
foreach([9,10,11,12,13] as $total){
    foreach(['none','date','label'] as $exam){
        $s->run('UPDATE enrollments SET adjustment=?,exam_date=?,exam_label=? WHERE participant_id=2',[$total,$exam==='date'?'2026-09-24':null,$exam==='label'?'bestanden':'']);
        $row=$overview->forAuthenticatedParticipant(2)['years'][0];
        if($row['completed']!==($total>=12&&$exam!=='none'))throw new RuntimeException('Completion boundary failed');
    }
}
$s->run('UPDATE participants SET archived=1 WHERE id=1');
if($overview->forAuthenticatedParticipant(1)!==null||$overview->forAuthenticatedParticipant(999)!==null)throw new RuntimeException('Unknown or archived participant exposed');
echo "PASS: personal overview, 10-participation threshold, no other participants or admin notes, archived access denied\n";
