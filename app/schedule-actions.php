<?php
declare(strict_types=1);
// Terminplan changes. Included only after the administrator and CSRF checks.
if($action==='schedule_save'){
    $page='schedule-entry';
    $id=number('id',0,PHP_INT_MAX);
    $input=['date'=>field('date','',10),'units'=>number('units',0,4,0)];
    // Line breaks may arrive as CRLF; the Schedule class enforces the real limits.
    foreach(Ausbildung\Schedule::TEXT_FIELDS as $name=>$max)$input[$name]=field($name,'',$max*2);
    $id=$schedule->save($id,$id?number('version',1,PHP_INT_MAX):0,$input);
    $_SESSION['flash']='Termin gespeichert. Termine & Anwesenheiten sind aktualisiert.';
    go('/?page=schedule&year='.(int)$schedule->find($id)['year'].'#termin-'.$id);
}
if($action==='schedule_delete'){
    $page='schedule-entry';
    $entry=$schedule->find(number('id',1,PHP_INT_MAX));
    if(!$entry)throw new RuntimeException('Termin nicht gefunden.');
    $kept=$schedule->delete((int)$entry['id']);
    $_SESSION['flash']='Termin gelöscht.'.($kept?' '.$kept.' Ausbildungseinheit(en) mit erfassten Anwesenheiten bleiben unter „Termine“ erhalten.':'');
    go('/?page=schedule&year='.(int)$entry['year']);
}
if($action==='schedule_settings'){
    $page='schedule';
    $year=number('year',2010,2100);
    $schedule->saveSettings($year,number('version',0,PHP_INT_MAX),field('title','',200),field('subtitle','',200),field('footer','',4000),isset($_POST['published']));
    $_SESSION['flash']='Kopf- und Fußtext gespeichert.';go('/?page=schedule&year='.$year);
}
if($action==='schedule_copy'){
    $page='schedule';
    $year=number('year',2010,2100);
    $n=$schedule->copyYear(number('source_year',2010,2100),$year);
    $_SESSION['flash']=$n.' Termine nach '.$year.' übernommen. Die Daten liegen auf demselben Wochentag wie im Vorjahr, bitte prüfen. Der Plan ist noch nicht für Teilnehmer freigegeben.';
    go('/?page=schedule&year='.$year);
}
