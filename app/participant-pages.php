<?php
declare(strict_types=1);
// Included after authentication processing. All IDs come from the server session.
if($participantAuth){
    if($page!=='me'&&$page!=='dashboard'){
        http_response_code(403);head('Kein Zugriff',false);
        echo '<p>Dieser Bereich ist der Ausbildungsverwaltung vorbehalten.</p><a href="/?page=me">Zu meinen Teilnahmen</a>';foot();exit;
    }
    $overview=(new Ausbildung\ParticipantOverview($s))->forAuthenticatedParticipant((int)$_SESSION['participant_id']);
    if(!$overview){unset($_SESSION['participant_id']);go('/?page=participant-login');}
    $p=$overview['person'];$latest=$overview['years'][0]??null;
    head('Meine Teilnahmen',false);
    echo '<div class="toolbar"><p>'.h($p['first_name'].' '.$p['last_name'].' · '.$p['department']).'</p><form method="post">'.csrf().'<input type="hidden" name="action" value="participant_logout"><button class="secondary">Abmelden</button></form></div>';
    if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
    if($latest){
        echo '<section class="card"><h2>Dein Ausbildungsstand</h2><div class="person-total"><strong>'.$latest['total'].' / 10</strong>'.status(['total'=>$latest['total'],'exam_date'=>$latest['exam_date'],'exam_label'=>$latest['exam_passed']?'bestanden':'']).'</div><p>Gesamtstand bis einschließlich '.$latest['year'].'. Für die Prüfungszulassung sind 10 Teilnahmen erforderlich. Ab 12 Teilnahmen und bestandener Prüfung ist die Ausbildung abgeschlossen.</p>';
        if($latest['exam_passed'])echo '<p class="badge passed">Prüfung bestanden'.($latest['exam_date']?' am '.h(date('d.m.Y',strtotime($latest['exam_date']))):'').'</p>';
        echo '</section>';
    }else echo '<p>Noch keine Ausbildungsstände hinterlegt.</p>';
    echo '<section class="card"><h2>Teilnahmen nach Jahr</h2><div class="table-wrap"><table><thead><tr><th>Jahr</th><th>Im Jahr</th><th>Gesamt bis Jahresstand</th></tr></thead><tbody>';
    foreach($overview['years']as$y)echo '<tr><td>'.$y['year'].'</td><td>'.$y['annual'].'</td><td>'.$y['total'].'</td></tr>';
    echo '</tbody></table></div><p class="muted">Gesamtstände enthalten gegebenenfalls Altbestände und bestätigte Korrekturen. Die Jahres-Gesamtstände werden nicht addiert.</p></section><section class="card"><h2>Wann war ich da?</h2><div class="table-wrap"><table><thead><tr><th>Datum / Jahr</th><th>Ausbildungseinheit(en)</th><th>Ausbildungsinhalt</th></tr></thead><tbody>';
    foreach($overview['attendance']as$l)echo '<tr><td>'.h($l['date']?date('d.m.Y',strtotime($l['date'])):$l['year'].' · Datum nicht hinterlegt').'</td><td>'.(int)$l['unit'].'</td><td>'.h($l['title']).'</td></tr>';
    if(!$overview['attendance'])echo '<tr><td colspan="3">Keine einzelnen Termine hinterlegt.</td></tr>';
    echo '</tbody></table></div></section><p>Bei Fragen oder Korrekturen wende dich bitte an die Ausbildungsleitung.</p>';foot();exit;
}
if(!$auth&&in_array($page,['participant-login','me'],true)){
    head('Meine Teilnahmen',false);
    echo '<section class="card auth"><h2>Teilnehmeranmeldung</h2><p>Gib deinen Namen und den gemeinsamen Ausbildungscode ein.</p>';
    if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
    if(!$access->settings()['enabled'])echo '<p>Der Teilnehmerzugang ist derzeit nicht freigeschaltet.</p>';
    else {
        $departments=[];
        foreach($s->rows("SELECT DISTINCT TRIM(department) AS department FROM participants WHERE archived=0 AND TRIM(department)<>''")as$row){
            $departments[Ausbildung\ParticipantAccess::normalize($row['department'])]=$row['department'];
        }
        $departments=array_values($departments);(new Collator('de_DE'))->sort($departments);
        $selectedDepartment=is_string($_POST['department']??null)?Ausbildung\ParticipantAccess::normalize($_POST['department']):'';
        echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="participant_login">'.input('Vorname','first_name','','text','required maxlength="100" autocomplete="given-name"').input('Nachname','last_name','','text','required maxlength="100" autocomplete="family-name"').input('Zentraler Ausbildungscode','access_code','','password','required maxlength="128" autocomplete="off"').'<label>Feuerwehr (nur bei gleichem Namen erforderlich)<select name="department"><option value="">Bitte auswählen (optional)</option>';
        foreach($departments as$department)echo '<option value="'.h($department).'" '.($selectedDepartment===Ausbildung\ParticipantAccess::normalize($department)?'selected':'').'>'.h($department).'</option>';
        echo '</select></label><button>Anmelden</button></form>';
    }
    echo '<p><a href="/">Zur Admin-Anmeldung</a></p></section>';foot();exit;
}
