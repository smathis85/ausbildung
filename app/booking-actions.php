<?php
declare(strict_types=1);
// Administrator actions for bookable events and the Einstellungen tab. Included only after the administrator and CSRF checks.
use Ausbildung\Mailer;
use Ausbildung\Settings;
if($action==='booking_event_save'){
    $page='booking-event';
    $id=number('id',0,PHP_INT_MAX);
    $in=['visible'=>isset($_POST['visible']),'remove_code'=>isset($_POST['remove_code']),'capacity'=>number('capacity',1,1000)];
    foreach(['title'=>200,'description'=>8000,'location'=>200,'date'=>10,'start'=>5,'end_date'=>10,'end'=>5,'code'=>128]as$name=>$max)$in[$name]=field($name,'',$max);
    $id=$bookings->saveEvent($id,$id?number('version',1,PHP_INT_MAX):0,$in);
    $_SESSION['flash']='Termin gespeichert.';go('/?page=booking-event&id='.$id);
}
if($action==='booking_event_cancel'){
    $page='booking-event';
    $id=number('id',1,PHP_INT_MAX);
    $affected=$bookings->cancelEvent($id);
    foreach($affected as $row)$bookingMail->cancelled($bookings->find((int)$row['id']),'event');
    $_SESSION['flash']='Termin abgesagt. '.count($affected).' Teilnehmende werden per E-Mail informiert.';go('/?page=booking-event&id='.$id);
}
if($action==='booking_event_delete'){
    $page='booking-event';
    $bookings->deleteEvent(number('id',1,PHP_INT_MAX));
    $_SESSION['flash']='Termin gelöscht.';go('/?page=bookings');
}
if($action==='booking_admin_add'){
    $page='booking-event';
    $eventId=number('event_id',1,PHP_INT_MAX);$_GET['id']=(string)$eventId;
    $result=$bookings->book($eventId,field('name','',120),field('department','',120),field('email','',254),true);
    if(isset($_POST['send_mail']))$bookingMail->confirmed($result['booking'],$result['token']);
    $_SESSION['flash']='Buchung für '.$result['booking']['name'].' eingetragen.'.(isset($_POST['send_mail'])?' Bestätigung wird per E-Mail versendet.':'');
    go('/?page=booking-event&id='.$eventId.'#teilnehmende');
}
if($action==='booking_admin_cancel'){
    $page='booking-event';
    $b=$bookings->cancelByAdmin(number('id',1,PHP_INT_MAX));
    if(isset($_POST['send_mail']))$bookingMail->cancelled($b,'admin');
    $_SESSION['flash']='Buchung von '.$b['name'].' storniert.';go('/?page=booking-event&id='.(int)$b['event_id'].'#teilnehmende');
}
if($action==='settings_booking'){
    $page='settings';
    $values=['booking_enabled'=>isset($_POST['booking_enabled'])?'1':'0','booking_code_required'=>isset($_POST['booking_code_required'])?'1':'0','booking_list_needs_code'=>isset($_POST['booking_list_needs_code'])?'1':'0',
        'booking_double_optin'=>isset($_POST['booking_double_optin'])?'1':'0','booking_notify_enabled'=>isset($_POST['booking_notify_enabled'])?'1':'0',
        'booking_close_hours'=>number('booking_close_hours',0,720),'booking_cancel_hours'=>number('booking_cancel_hours',0,720),'booking_max_per_email'=>number('booking_max_per_email',0,50),
        'booking_optin_minutes'=>number('booking_optin_minutes',5,1440),'booking_rate_per_hour'=>number('booking_rate_per_hour',1,1000),'booking_rate_codes'=>number('booking_rate_codes',1,1000),
        'booking_retention_days'=>number('booking_retention_days',1,3650),
        'booking_department_mode'=>field('booking_department_mode','list',10)==='free'?'free':'list',
        'booking_notify_email'=>trim(field('booking_notify_email','',254)),'booking_confirm_text'=>trim(field('booking_confirm_text','',2000)),'booking_privacy_text'=>trim(field('booking_privacy_text','',1000))];
    if($values['booking_notify_email']!==''&&!Mailer::validAddress($values['booking_notify_email']))throw new RuntimeException('Die Adresse für Benachrichtigungen ist ungültig.');
    if($values['booking_notify_enabled']==='1'&&$values['booking_notify_email']==='')throw new RuntimeException('Für Benachrichtigungen bitte eine E-Mail-Adresse angeben.');
    if(mb_strlen($values['booking_privacy_text'])<10)throw new RuntimeException('Bitte einen Datenschutzhinweis angeben.');
    $code=field('booking_code','',128);
    if($code!==''){
        if(mb_strlen($code)<6)throw new RuntimeException('Der Buchungscode muss mindestens 6 Zeichen lang sein.');
        $values['booking_code_hash']=Settings::codeHash($code);
        $values['booking_code_version']=(string)($appSettings->int('booking_code_version')+1);
    }
    if($values['booking_code_required']==='1'&&$code===''&&$appSettings->get('booking_code_hash')==='')throw new RuntimeException('Bitte zuerst einen Buchungscode festlegen oder „Buchungscode verlangen“ ausschalten.');
    $appSettings->save($values,number('settings_version',1,PHP_INT_MAX),'booking_settings_changed');
    $_SESSION['flash']='Einstellungen zur Terminbuchung gespeichert.'.($code!==''?' Der neue Buchungscode gilt ab sofort.':'');go('/?page=settings');
}
if($action==='settings_mail'){
    $page='settings-mail';
    $values=['mail_from_name'=>trim(field('mail_from_name','',100)),'mail_from'=>trim(field('mail_from','',254)),'mail_reply_to'=>trim(field('mail_reply_to','',254)),
        'smtp_host'=>trim(field('smtp_host','',200)),'smtp_port'=>number('smtp_port',1,65535),'smtp_user'=>trim(field('smtp_user','',254)),'mail_signature'=>trim(field('mail_signature','',1000))];
    $values['smtp_security']=in_array($security=field('smtp_security','starttls',10),['starttls','tls','none'],true)?$security:'starttls';
    foreach(['mail_from','mail_reply_to']as$k)if($values[$k]!==''&&!Mailer::validAddress($values[$k]))throw new RuntimeException('Bitte eine gültige Absender- bzw. Antwortadresse angeben.');
    if($values['smtp_host']!==''&&!preg_match('/^[A-Za-z0-9.-]+$|^::1$/',$values['smtp_host']))throw new RuntimeException('Ungültiger Mailserver.');
    $password=field('smtp_password','',256);
    if(isset($_POST['smtp_password_clear']))$values['smtp_password_enc']='';
    elseif($password!=='')$values['smtp_password_enc']=$mailer->encrypt($password);
    $appSettings->save($values,number('settings_version',1,PHP_INT_MAX),'mail_settings_changed');
    $_SESSION['flash']='E-Mail-Einstellungen gespeichert. Bitte eine Testmail senden.';go('/?page=settings-mail');
}
if($action==='settings_mail_test'){
    $page='settings-mail';
    $to=trim(field('test_to','',254));
    $mailer->send($to,'Testmail der Ausbildungsverwaltung',"Hallo,\n\ndiese Testmail bestätigt, dass der E-Mail-Versand der Terminbuchung funktioniert.\n\n".trim($appSettings->get('mail_signature')));
    $s->audit('mail_test_sent','settings');
    $_SESSION['flash']='Testmail an '.$to.' versendet.';go('/?page=settings-mail');
}
if($action==='settings_mail_retry'){
    $page='settings-mail';
    $n=$mailer->retryFailed();
    $_SESSION['flash']=$n.' E-Mail(s) werden erneut versucht.';go('/?page=settings-mail');
}
