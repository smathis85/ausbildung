<?php
declare(strict_types=1);
// Administrator views of the Terminbuchung, rendered through the authenticated main router.
use Ausbildung\Bookings;
$publicLink=TRAINING_BASE_URL.'/?page=termine';
if($page==='bookings'){
    if(!$appSettings->on('booking_enabled'))echo '<p class="notice">Die öffentliche Terminbuchung ist ausgeschaltet. Einschalten unter <a href="/?page=settings">Einstellungen › Terminbuchung</a>.</p>';
    if(!$mailer->configured())echo '<p class="notice">Der E-Mail-Versand ist noch nicht eingerichtet. Ohne ihn erhalten Teilnehmende keine Bestätigung: <a href="/?page=settings-mail">Einstellungen › E-Mail</a>.</p>';
    echo '<section class="card"><div class="toolbar"><div><h2>Buchbare Termine</h2><p class="muted">Öffentliche Buchungsseite: <a href="'.h($publicLink).'">'.h($publicLink).'</a></p></div><a class="button" href="/?page=booking-event">Termin anlegen</a></div>';
    $all=$bookings->events();$now=date('Y-m-d H:i');
    $upcoming=array_filter($all,fn($e)=>($e['ends_at']??$e['starts_at'])>=$now);$past=array_reverse(array_filter($all,fn($e)=>($e['ends_at']??$e['starts_at'])<$now));
    $table=function(array $list)use($bookings){
        $out='<div class="table-wrap"><table><thead><tr><th>Termin</th><th>Titel</th><th>Belegung</th><th>Status</th></tr></thead><tbody>';
        foreach($list as $e)$out.='<tr><td>'.h(Bookings::when($e)).'</td><td><a href="/?page=booking-event&amp;id='.(int)$e['id'].'">'.h($e['title']).'</a>'.($e['code_hash']?' <small>eigener Code</small>':'').'</td><td><strong>'.(int)$e['taken'].' / '.(int)$e['capacity'].'</strong></td><td>'
            .((int)$e['cancelled']?'<span class="badge cancelled">Abgesagt</span>':(!(int)$e['visible']?'<span class="badge">Entwurf</span>':($bookings->free($e)?'<span class="badge ready">Buchbar</span>':'<span class="badge">Ausgebucht</span>'))).'</td></tr>';
        return $out.'</tbody></table></div>';
    };
    echo $upcoming?$table($upcoming):'<p>Noch keine kommenden Termine angelegt.</p>';
    echo '</section>';
    if($past)echo '<details class="card"><summary>Vergangene Termine ('.count($past).')</summary>'.$table($past).'<p class="muted">Buchungsdaten werden '.$appSettings->int('booking_retention_days').' Tage nach dem Termin automatisch gelöscht.</p></details>';
}
if($page==='booking-event'){
    $event=$id?$bookings->event($id):null;
    if($id&&!$event){http_response_code(404);echo '<p>Termin nicht gefunden.</p>';foot();exit;}
    $posted=$error&&($_POST['action']??'')==='booking_event_save';
    $p=fn(string $k,string $default)=>$posted&&is_string($_POST[$k]??null)?$_POST[$k]:$default;
    echo '<p class="no-print"><a href="/?page=bookings">← Zu den buchbaren Terminen</a></p>';
    if($event){
        $list=$bookings->forEvent($id);$active=array_values(array_filter($list,fn($b)=>(int)$b['active']));
        echo '<section class="card"><div class="toolbar"><div><h2>'.h($event['title']).'</h2><p><strong>'.h(Bookings::when($event)).'</strong>'.($event['location']!==''?' · '.h($event['location']):'').'</p></div><div class="person-total"><strong>'.(int)$event['taken'].' / '.(int)$event['capacity'].'</strong></div></div>';
        if((int)$event['cancelled'])echo '<p class="notice">Dieser Termin wurde abgesagt.</p>';
        elseif(!(int)$event['visible'])echo '<p class="notice no-print">Entwurf: Der Termin ist noch nicht öffentlich buchbar.</p>';
        else echo '<p class="muted no-print">Direkter Link: <a href="'.h(TRAINING_BASE_URL.'/?page=termin&id='.$id).'">'.h(TRAINING_BASE_URL.'/?page=termin&id='.$id).'</a></p>';
        if($event['description']!=='')echo '<p class="pre">'.h($event['description']).'</p>';
        echo '</section><section class="card" id="teilnehmende"><div class="toolbar"><h2>Teilnehmende ('.count($active).')</h2><div class="actions no-print"><button type="button" class="secondary" id="print">Liste drucken</button><a class="button secondary" href="/?page=booking-export&amp;id='.$id.'">CSV exportieren</a></div></div><div class="table-wrap"><table class="booking-table"><thead><tr><th>#</th><th>Name</th><th>Feuerwehr</th><th>E-Mail</th><th>Gebucht am</th><th class="no-print">Status</th><th class="print-only">Unterschrift</th></tr></thead><tbody>';
        $n=0;
        foreach($list as $b){
            $isActive=(int)$b['active'];
            $state=$b['status']==='pending'?'<span class="badge">Unbestätigt</span>':($isActive?'<span class="badge passed">Gebucht</span>':'<span class="badge cancelled">Storniert</span>');
            echo '<tr class="'.($isActive?'':'print-skip muted-row').'"><td>'.($isActive?++$n:'').'</td><td>'.h($b['name']).($b['source']==='admin'?' <small>eingetragen</small>':'').'</td><td>'.h($b['department']).'</td><td>'.h($b['email']).'</td><td>'.h(date('d.m.Y H:i',(int)$b['created_at'])).'</td><td class="no-print">'.$state;
            if($isActive&&!(int)$event['cancelled'])echo '<form method="post" class="inline" data-confirm="Buchung von '.h($b['name']).' stornieren?">'.csrf().'<input type="hidden" name="action" value="booking_admin_cancel"><input type="hidden" name="id" value="'.(int)$b['id'].'"><label class="check"><input type="checkbox" name="send_mail" checked> E-Mail</label><button class="subtle-link">Stornieren</button></form>';
            echo '</td><td class="print-only sign-cell"></td></tr>';
        }
        if(!$list)echo '<tr><td colspan="6">Noch keine Buchungen.</td></tr>';
        echo '</tbody></table></div></section>';
        if(!(int)$event['cancelled'])echo '<details class="card no-print"><summary>Person manuell eintragen</summary><p>Zählt zur Teilnehmerzahl, auch über den Buchungsschluss hinaus. Codes und Grenzen gelten hier nicht.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="booking_admin_add"><input type="hidden" name="event_id" value="'.$id.'"><div class="form-grid">'
            .input('Vor- und Nachname','name','','text','required maxlength="120"').input('Feuerwehr','department','','text','required maxlength="120"').input('E-Mail-Adresse','email','','email','required maxlength="254"')
            .'</div><label class="check"><input type="checkbox" name="send_mail" checked> Bestätigung mit Link zum Stornieren/Umbuchen per E-Mail senden</label><button>Eintragen</button></form></details>';
    }
    $start=$event?new DateTimeImmutable($event['starts_at']):null;$end=$event&&$event['ends_at']?new DateTimeImmutable($event['ends_at']):null;
    echo '<'.($event&&!$posted?'details':'section').' class="card no-print">'.($event?'<summary>Termin bearbeiten</summary>':'<h2>Termin anlegen</h2>').'<form method="post">'.csrf().'<input type="hidden" name="action" value="booking_event_save"><input type="hidden" name="id" value="'.(int)$id.'"><input type="hidden" name="version" value="'.(int)($event['version']??1).'">'
        .input('Titel','title',$p('title',$event['title']??''),'text','required maxlength="200"')
        .'<div class="form-grid">'.input('Datum','date',$p('date',$start?$start->format('Y-m-d'):''),'date','required').input('Beginn','start',$p('start',$start?$start->format('H:i'):'19:00'),'time','required')
        .input('Ende','end',$p('end',$end?$end->format('H:i'):''),'time').input('Enddatum (nur bei mehrtägigen Terminen)','end_date',$p('end_date',$end&&$end->format('Y-m-d')!==$start->format('Y-m-d')?$end->format('Y-m-d'):''),'date')
        .input('Ort','location',$p('location',$event['location']??''),'text','maxlength="200"').input('Max. Teilnehmerzahl','capacity',$p('capacity',(string)($event['capacity']??20)),'number','required min="1" max="1000"').'</div>'
        .'<label>Beschreibung (erscheint auf der Buchungsseite und in der Bestätigung)<textarea name="description" maxlength="4000" rows="5">'.h($p('description',$event['description']??'')).'</textarea></label>'
        .input($event&&$event['code_hash']?'Eigener Buchungscode (leer lassen, um ihn zu behalten)':'Eigener Buchungscode für diesen Termin (optional, ersetzt den allgemeinen Code)','code','','password','minlength="6" maxlength="128" autocomplete="new-password"')
        .($event&&$event['code_hash']?'<label class="check"><input type="checkbox" name="remove_code"> Eigenen Code entfernen (dann gilt wieder der allgemeine Buchungscode)</label>':'')
        .'<label class="check"><input type="checkbox" name="visible" '.(($posted?isset($_POST['visible']):(int)($event['visible']??1))?'checked':'').'> Öffentlich buchbar</label><button>'.($event?'Speichern':'Termin anlegen').'</button></form></'.($event&&!$posted?'details':'section').'>';
    if($event&&!(int)$event['cancelled'])echo '<details class="card no-print"><summary>Termin absagen</summary><p>Alle Gebuchten erhalten eine Absage per E-Mail. Die Absage kann nicht rückgängig gemacht werden.</p><form method="post" data-confirm="Termin wirklich absagen und alle Gebuchten informieren?">'.csrf().'<input type="hidden" name="action" value="booking_event_cancel"><input type="hidden" name="id" value="'.$id.'"><button class="danger">Termin absagen</button></form></details>';
    if($event&&(!(int)$event['taken']||(int)$event['cancelled']))echo '<details class="card no-print"><summary>Termin löschen</summary><p>Entfernt den Termin und alle stornierten Buchungen endgültig.</p><form method="post" data-confirm="Termin endgültig löschen?">'.csrf().'<input type="hidden" name="action" value="booking_event_delete"><input type="hidden" name="id" value="'.$id.'"><button class="danger">Löschen</button></form></details>';
}
