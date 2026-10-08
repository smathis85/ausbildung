<?php
declare(strict_types=1);
// Terminplan rendering, shared by administrators and participants. The same markup is the print layout.
function multiline(string $text): string { return nl2br(h($text),false); }
function schedule_date(string $date): string { return h(Ausbildung\Schedule::weekday($date)).'<br>'.h(date('d.m.Y',strtotime($date))); }
function schedule_document(array $settings,array $entries,bool $admin): string {
    $html='<article class="schedule-doc"><div class="schedule-head"><img class="schedule-logo" src="/?asset=brand-logo" width="60" height="70" alt="Wappen der Verbandsgemeinde Selters"><div><p class="schedule-title">'.h($settings['title']).'</p><p class="schedule-subtitle"><strong>- Terminplan für das Jahr '.(int)$settings['year'].' -</strong>'.($settings['subtitle']!==''?'<br>'.h($settings['subtitle']):'').'</p></div></div>';
    if(!$entries)return $html.'<p>Für '.(int)$settings['year'].' sind noch keine Termine eingetragen.</p></article>';
    $html.='<div class="table-wrap"><table class="schedule-table"><thead><tr><th>Termin</th><th>Dauer</th><th>Ausbildungsinhalte/-methode</th><th>Fahrzeuge</th><th>Ausbilder</th>'.($admin?'<th class="no-print">Verwaltung</th>':'').'</tr></thead><tbody>';
    $today=date('Y-m-d');
    foreach($entries as $e){
        $units=Ausbildung\Schedule::units($e);
        $html.='<tr id="termin-'.(int)$e['id'].'"'.($e['date']<$today?' class="past"':'').'><td class="schedule-when">'.schedule_date($e['date']).'</td>';
        $html.='<td class="schedule-time">'.multiline($e['time_text']).'<small class="no-print">'.$units.' '.($units===1?'Ausbildungseinheit':'Ausbildungseinheiten').'</small></td>';
        $html.='<td><span class="schedule-topic">'.h($e['topic']).'</span>'.($e['content']!==''?'<br>'.multiline($e['content']):'').($e['notes']!==''?'<span class="schedule-note">'.multiline($e['notes']).'</span>':'').'</td>';
        $html.='<td>'.multiline($e['vehicles']).'</td><td>'.multiline($e['instructors']).'</td>';
        if($admin)$html.='<td class="no-print"><a href="/?page=schedule-entry&id='.(int)$e['id'].'">Bearbeiten</a><br><a href="/?page=schedule-entry&copy='.(int)$e['id'].'">Kopieren</a><small>'.(int)$e['attendance'].' Teilnahmen erfasst</small></td>';
        $html.='</tr>';
    }
    $html.='</tbody></table></div>';
    if(trim($settings['footer'])!=='')$html.='<p class="schedule-footer">'.multiline($settings['footer']).'</p>';
    return $html.'</article>';
}
function schedule_year_select(string $page,array $years,int $year): string {
    $html='<form class="filters no-print" method="get"><input type="hidden" name="page" value="'.h($page).'"><label>Jahr<select name="year">';
    foreach($years as $y)$html.='<option '.($y===$year?'selected':'').'>'.$y.'</option>';
    return $html.'</select></label><button class="secondary">Jahr anzeigen</button></form>';
}
