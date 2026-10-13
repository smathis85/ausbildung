<?php
declare(strict_types=1);
// Einstellungen: Terminbuchung and E-Mail. Unterlagen, Importprüfung and Zugang are linked as further sections.
$c=$appSettings->all();
$posted=$error!==null&&in_array($_POST['action']??'',['settings_booking','settings_mail'],true);
$v=fn(string $k)=>$posted&&is_string($_POST[$k]??null)?$_POST[$k]:$c[$k];
$on=fn(string $k)=>($posted?isset($_POST[$k]):$c[$k]==='1')?'checked':'';
$version='<input type="hidden" name="settings_version" value="'.(int)$c['version'].'">';
if($page==='settings'){
    $link=TRAINING_BASE_URL.'/?page=termine';
    echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="settings_booking">'.$version
        .'<section class="card"><h2>Terminbuchung</h2><p>Öffentliche Buchungsseite: <a href="'.h($link).'">'.h($link).'</a>. Buchen kann jeder, ohne Konto und Passwort. Die Termine selbst legst du unter <a href="/?page=bookings">Buchungen</a> an.</p>'
        .'<label class="check"><input type="checkbox" name="booking_enabled" '.$on('booking_enabled').'> Terminbuchung freischalten</label>'
        .(!$mailer->configured()?'<p class="notice">Der E-Mail-Versand ist noch nicht eingerichtet. Ohne ihn kommen keine Bestätigungen an: <a href="/?page=settings-mail">E-Mail einrichten</a>.</p>':'')
        .'<div class="form-grid">'.input('Buchungsschluss (Stunden vor Beginn, 0 = bis Beginn)','booking_close_hours',$v('booking_close_hours'),'number','min="0" max="720" required')
        .input('Stornieren/Umbuchen bis (Stunden vor Beginn)','booking_cancel_hours',$v('booking_cancel_hours'),'number','min="0" max="720" required')
        .input('Max. offene Buchungen je E-Mail-Adresse (0 = unbegrenzt)','booking_max_per_email',$v('booking_max_per_email'),'number','min="0" max="50" required')
        .'<label>Feuerwehr im Buchungsformular<select name="booking_department_mode"><option value="list" '.($v('booking_department_mode')==='list'?'selected':'').'>Auswahlliste (Feuerwehren der VG) plus „Andere“</option><option value="free" '.($v('booking_department_mode')==='free'?'selected':'').'>Freitext</option></select></label></div>'
        .'<label>Zusatztext in der Bestätigungsmail (optional, z. B. Treffpunkt, Ausrüstung)<textarea name="booking_confirm_text" maxlength="2000" rows="3">'.h($v('booking_confirm_text')).'</textarea></label>'
        .'<label>Datenschutzhinweis (muss vor der Buchung bestätigt werden)<textarea name="booking_privacy_text" maxlength="1000" rows="3" required>'.h($v('booking_privacy_text')).'</textarea></label>'
        .input('Buchungsdaten löschen nach … Tagen (nach dem Termin)','booking_retention_days',$v('booking_retention_days'),'number','min="1" max="3650" required').'</section>'
        .'<section class="card"><h2>Schutz vor Spam und Bots</h2><p>Unsichtbare Prüfungen (verstecktes Feld, Mindestzeit beim Ausfüllen) sind immer aktiv.</p>'
        .'<label class="check"><input type="checkbox" name="booking_code_required" '.$on('booking_code_required').'> Buchungscode verlangen</label>'
        .'<p>Status: <strong>'.($c['booking_code_hash']!==''?'Code festgelegt':'Noch kein Code festgelegt').'</strong>. Der Code wird geschützt gespeichert und nicht wieder angezeigt. Einzelne Termine können einen eigenen Code haben.</p>'
        .input($c['booking_code_hash']!==''?'Neuer Buchungscode (leer lassen, um den bestehenden zu behalten)':'Buchungscode festlegen','booking_code','','password','minlength="6" maxlength="128" autocomplete="new-password"')
        .'<label class="check"><input type="checkbox" name="booking_list_needs_code" '.$on('booking_list_needs_code').'> Terminliste erst nach Eingabe des Codes zeigen</label>'
        .'<label class="check"><input type="checkbox" name="booking_double_optin" '.$on('booking_double_optin').'> Buchung erst nach Klick auf den Link in der E-Mail gültig (Double-Opt-in)</label>'
        .'<div class="form-grid">'.input('Bestätigungsfrist bei Double-Opt-in (Minuten)','booking_optin_minutes',$v('booking_optin_minutes'),'number','min="5" max="1440" required')
        .input('Max. Buchungsversuche je Internetanschluss und Stunde','booking_rate_per_hour',$v('booking_rate_per_hour'),'number','min="1" max="1000" required')
        .input('Max. Code-Eingaben je Internetanschluss in 15 Minuten','booking_rate_codes',$v('booking_rate_codes'),'number','min="1" max="1000" required').'</div></section>'
        .'<section class="card"><h2>Benachrichtigung</h2><label class="check"><input type="checkbox" name="booking_notify_enabled" '.$on('booking_notify_enabled').'> Bei jeder Buchung, Stornierung und Umbuchung eine E-Mail senden an</label>'
        .input('E-Mail-Adresse','booking_notify_email',$v('booking_notify_email'),'email','maxlength="254"').'</section>'
        .'<div class="save-bar"><button>Einstellungen speichern</button></div></form>';
}
if($page==='settings-mail'){
    $st=$mailer->status();
    echo '<section class="card"><h2>Status</h2><p>'.($mailer->configured()?'<span class="badge passed">Eingerichtet</span>':'<span class="badge">Noch nicht eingerichtet</span>').' · Warteschlange: '.$st['open'].' · Fehlgeschlagen: '.$st['failed'].($st['last_sent']?' · Zuletzt versendet: '.h(date('d.m.Y H:i',$st['last_sent'])):'').'</p>';
    if($st['last_error']!=='')echo '<p class="muted">Letzte Fehlermeldung: '.h($st['last_error']).'</p>';
    if($st['failed'])echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="settings_mail_retry"><button class="secondary">Fehlgeschlagene erneut senden</button></form>';
    echo '<p class="muted">E-Mails werden sofort versendet. Klappt das nicht, versucht es das System alle 5 Minuten erneut (bis zu 5 Mal).</p></section>';
    echo '<form method="post" class="card">'.csrf().'<input type="hidden" name="action" value="settings_mail">'.$version.'<h2>Absender und Mailserver</h2><p>Am besten ein eigenes Postfach nur für die Ausbildung verwenden, unabhängig von Einsatzleiter.app.</p><div class="form-grid">'
        .input('Absendername','mail_from_name',$v('mail_from_name'),'text','maxlength="100"').input('Absenderadresse','mail_from',$v('mail_from'),'email','maxlength="254"')
        .input('Antwortadresse (optional)','mail_reply_to',$v('mail_reply_to'),'email','maxlength="254"').'<span></span>'
        .input('SMTP-Server (z. B. smtp.ionos.de)','smtp_host',$v('smtp_host'),'text','maxlength="200"').input('Port','smtp_port',$v('smtp_port'),'number','min="1" max="65535" required')
        .'<label>Verschlüsselung<select name="smtp_security">';
    foreach(['starttls'=>'STARTTLS (Port 587)','tls'=>'SSL/TLS (Port 465)','none'=>'Keine (nur lokaler Mailserver)']as$key=>$label)echo '<option value="'.$key.'" '.($v('smtp_security')===$key?'selected':'').'>'.$label.'</option>';
    echo '</select></label>'.input('Benutzername','smtp_user',$v('smtp_user'),'text','maxlength="254" autocomplete="off"').'</div>'
        .input($c['smtp_password_enc']!==''?'Passwort (leer lassen, um das gespeicherte zu behalten)':'Passwort','smtp_password','','password','maxlength="256" autocomplete="new-password"')
        .($c['smtp_password_enc']!==''?'<label class="check"><input type="checkbox" name="smtp_password_clear"> Gespeichertes Passwort löschen</label>':'')
        .'<p class="muted">Das Passwort wird verschlüsselt gespeichert und nicht wieder angezeigt.</p>'
        .'<label>Signatur unter jeder E-Mail<textarea name="mail_signature" maxlength="1000" rows="3">'.h($v('mail_signature')).'</textarea></label><button>Speichern</button></form>';
    echo '<section class="card"><h2>Testmail</h2><form method="post" class="filters">'.csrf().'<input type="hidden" name="action" value="settings_mail_test">'.input('Empfänger','test_to',$account['email'],'email','required maxlength="254"').'<button class="secondary">Testmail senden</button></form></section>';
}
