<?php
declare(strict_types=1);
// Administrator views of the Terminplan, rendered through the authenticated main router.
use Ausbildung\Schedule;
if($page==='schedule'){
    $scheduleYear=filter_var($_GET['year']??date('Y'),FILTER_VALIDATE_INT);
    if(!$scheduleYear||$scheduleYear<2010||$scheduleYear>2100)$scheduleYear=(int)date('Y');
    $choices=array_unique(array_merge($schedule->years(false),$years,[(int)date('Y'),(int)date('Y')+1,$scheduleYear]));rsort($choices);
    echo schedule_year_select('schedule',$choices,$scheduleYear);
    $settings=$schedule->settings($scheduleYear);$entries=$schedule->entries($scheduleYear);
    echo '<section class="card schedule-card"><div class="toolbar no-print"><div><h2>Terminplan '.$scheduleYear.'</h2><p>'.count($entries).' Termine · '.($settings['published']?'<span class="badge ready">Für Teilnehmer sichtbar</span>':'<span class="badge">Entwurf, für Teilnehmer nicht sichtbar</span>').'</p></div><div class="actions"><a class="button" href="/?page=schedule-entry&year='.$scheduleYear.'">Termin hinzufügen</a><button type="button" class="secondary" id="print">Drucken</button></div></div>';
    echo schedule_document($settings,$entries,true).'</section>';
    echo '<p class="muted">Jeder Termin legt seine Ausbildungseinheiten unter „Termine“ an (Samstag automatisch 2, sonst 1). Dort wird wie bisher die Anwesenheit erfasst. Datumsänderungen hier gelten auch dort.</p>';
    echo '<details class="card"><summary>Kopf- und Fußtext, Freigabe für Teilnehmer</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="schedule_settings"><input type="hidden" name="year" value="'.$scheduleYear.'"><input type="hidden" name="version" value="'.(int)$settings['version'].'">'
        .input('Überschrift','title',$settings['title'],'text','required maxlength="200"').input('Zusatz unter „Terminplan für das Jahr …“','subtitle',$settings['subtitle'],'text','maxlength="200"')
        .'<label>Hinweise unter dem Plan<textarea name="footer" maxlength="2000" rows="5">'.h($settings['footer']).'</textarea></label><label class="check"><input type="checkbox" name="published" '.($settings['published']?'checked':'').'> Terminplan '.$scheduleYear.' für Teilnehmer sichtbar</label><button>Speichern</button></form></details>';
    $sources=array_values(array_filter($schedule->years(false),fn($y)=>$y<$scheduleYear));
    if($entries)echo '<details class="card"><summary>Terminplan in ein neues Jahr übernehmen</summary><p>Übernimmt alle Themen, Fahrzeuge und Ausbilder. Jedes Datum wird auf denselben Wochentag im Zieljahr gelegt und muss danach geprüft werden. Der neue Plan bleibt zunächst für Teilnehmer unsichtbar.</p><form method="post" class="filters">'.csrf().'<input type="hidden" name="action" value="schedule_copy"><input type="hidden" name="source_year" value="'.$scheduleYear.'">'.input('Zieljahr','year',$scheduleYear+1,'number','min="2011" max="2100" required').'<button>Übernehmen</button></form></details>';
    elseif($sources)echo '<section class="card"><h2>Aus einem Vorjahr übernehmen</h2><p>Die Themen sind meist gleich: Übernimm den Plan eines Vorjahres und passe danach Daten, Fahrzeuge und Ausbilder an.</p><form method="post" class="filters">'.csrf().'<input type="hidden" name="action" value="schedule_copy"><input type="hidden" name="year" value="'.$scheduleYear.'"><label>Vorlage<select name="source_year">'.implode('',array_map(fn($y)=>'<option>'.$y.'</option>',$sources)).'</select></label><button>Terminplan '.$scheduleYear.' anlegen</button></form></section>';
}
if($page==='schedule-entry'){
    $entry=$id?$schedule->find($id):null;
    if($id&&!$entry){http_response_code(404);echo '<p>Termin nicht gefunden.</p>';foot();exit;}
    $template=!$entry&&filter_var($_GET['copy']??0,FILTER_VALIDATE_INT)?$schedule->find((int)$_GET['copy']):null;
    $entryYear=$entry?(int)$entry['year']:($template?(int)$template['year']:$year);
    $values=$entry??$template??['date'=>'','units'=>null,'time_text'=>Schedule::WEEKDAY_TIME,'topic'=>'','content'=>'','vehicles'=>'','instructors'=>'','notes'=>''];
    if($template)$values['date']='';
    // Keep what was typed when saving failed.
    if($error&&($_POST['action']??'')==='schedule_save'){
        foreach(array_merge(array_keys(Schedule::TEXT_FIELDS),['date','units']) as $name)if(is_string($_POST[$name]??null))$values[$name]=$_POST[$name];
        if((int)$values['units']===0)$values['units']=null;
    }
    echo '<a href="/?page=schedule&year='.$entryYear.'">← Zum Terminplan '.$entryYear.'</a>';
    echo '<section class="card"><h2>'.($entry?'Termin bearbeiten':($template?'Termin kopieren':'Termin hinzufügen')).'</h2>';
    if($template)echo '<p class="notice">Vorlage: „'.h($template['topic']).'“ vom '.h(date('d.m.Y',strtotime($template['date']))).'. Bitte das neue Datum eintragen.</p>';
    echo '<form method="post" class="schedule-form">'.csrf().'<input type="hidden" name="action" value="schedule_save"><input type="hidden" name="id" value="'.($entry?(int)$entry['id']:0).'"><input type="hidden" name="version" value="'.($entry?(int)$entry['version']:1).'">';
    echo '<div class="form-grid">'.input('Datum','date',$values['date'],'date','required');
    echo '<label>Ausbildungseinheiten<select name="units"><option value="0">Automatisch (Samstag 2, sonst 1)</option>';
    for($n=1;$n<=4;$n++)echo '<option value="'.$n.'" '.((string)($values['units']??'')===(string)$n?'selected':'').'>'.$n.'</option>';
    echo '</select></label></div>';
    echo '<div class="form-grid"><label>Dauer / Uhrzeit<textarea name="time_text" rows="7" maxlength="300" data-weekday="'.h(Schedule::WEEKDAY_TIME).'" data-saturday="'.h(Schedule::SATURDAY_TIME).'">'.h($values['time_text']).'</textarea></label>';
    echo '<label>Thema (wird unterstrichen)<input type="text" name="topic" value="'.h($values['topic']).'" required maxlength="200" list="schedule-topics"></label></div><datalist id="schedule-topics">';
    foreach($s->rows('SELECT DISTINCT topic FROM schedule_entries ORDER BY topic COLLATE NOCASE') as $t)echo '<option value="'.h($t['topic']).'">';
    echo '</datalist><div class="form-grid"><label>Ausbildungsinhalte/-methode<textarea name="content" rows="5" maxlength="2000">'.h($values['content']).'</textarea></label>';
    echo '<label>Hinweise (rot gedruckt, z. B. Ort, Kleidung, max. Teilnehmer)<textarea name="notes" rows="5" maxlength="1000">'.h($values['notes']).'</textarea></label>';
    echo '<label>Fahrzeuge (eine Zeile je Fahrzeug)<textarea name="vehicles" rows="8" maxlength="2000">'.h($values['vehicles']).'</textarea></label>';
    echo '<label>Ausbilder (eine Zeile je Person, z. B. Mathis, Sascha)<textarea name="instructors" rows="8" maxlength="2000">'.h($values['instructors']).'</textarea></label></div>';
    echo '<button>Termin speichern</button></form></section>';
    if($entry){
        echo '<section class="card"><h2>Anwesenheit</h2><ul class="unit-links">';
        foreach($schedule->lessons((int)$entry['id']) as $l)echo '<li><a href="/?page=lesson&id='.(int)$l['id'].'">Ausbildungseinheit '.(int)$l['unit'].'</a> · '.(int)$l['count'].' anwesend</li>';
        echo '</ul></section><details class="card"><summary>Termin löschen</summary><p>Ausbildungseinheiten ohne Anwesenheit werden mit gelöscht. Einheiten mit erfassten Anwesenheiten bleiben unter „Termine“ erhalten.</p><form method="post" data-confirm="Termin „'.h($entry['topic']).'“ löschen?">'.csrf().'<input type="hidden" name="action" value="schedule_delete"><input type="hidden" name="id" value="'.(int)$entry['id'].'"><button class="danger">Termin löschen</button></form></details>';
    }
}
