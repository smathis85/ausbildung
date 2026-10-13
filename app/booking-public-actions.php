<?php
declare(strict_types=1);
// Public Terminbuchung actions (no login). Included after the CSRF check, before the administrator check.
use Ausbildung\Bookings;
use Ausbildung\Settings;
$ip=$_SERVER['REMOTE_ADDR']??'unknown';
if(in_array($action,['booking_unlock','book'],true)&&!$appSettings->on('booking_enabled')){$page='termine';throw new RuntimeException('Die Terminbuchung ist derzeit nicht freigeschaltet.');}
if($action==='booking_unlock'){
    $page='termine';
    $bookings->limit('code:'.$ip,$appSettings->int('booking_rate_codes'),900);
    if(!Settings::codeMatches($appSettings->get('booking_code_hash'),field('booking_code','',128)))throw new RuntimeException('Der Buchungscode stimmt nicht.');
    $_SESSION['booking_unlocked']=$appSettings->int('booking_code_version');
    go('/?page=termine');
}
if($action==='book'){
    $page='termin';
    $eventId=number('event_id',1,PHP_INT_MAX);$_GET['id']=(string)$eventId;
    $event=$bookings->event($eventId);
    if(!$event||!$bookings->bookingOpen($event))throw new RuntimeException('Für diesen Termin ist keine Buchung (mehr) möglich.');
    // Bots: hidden field must stay empty, the form must have been loaded in this session a few seconds ago.
    $shown=$_SESSION['booking_form'][$eventId]??0;
    if(field('website','',200)!==''||!$shown||time()-$shown<3)throw new RuntimeException('Die Buchung konnte nicht angenommen werden. Bitte lade die Seite neu und versuche es nach einem Moment erneut.');
    $bookings->limit('book:'.$ip,$appSettings->int('booking_rate_per_hour'),3600);
    if(booking_code_needed($event))booking_check_code($event,field('booking_code','',128));
    if(!isset($_POST['privacy']))throw new RuntimeException('Bitte den Datenschutzhinweis bestätigen.');
    $department=field('department','',120);
    if($department==='__other'||$appSettings->get('booking_department_mode')==='free')$department=field('department_other','',120);
    $result=$bookings->book($eventId,field('name','',120),$department,field('email','',254));
    $b=$result['booking'];
    if($b['status']==='pending')$bookingMail->pending($b,$result['token']);
    else{$bookingMail->confirmed($b,$result['token']);$bookingMail->notifyAdmin('Neue Buchung',$b);}
    unset($_SESSION['booking_form'][$eventId]);
    $_SESSION['booking_done']=['title'=>$b['title'],'when'=>Bookings::when($b),'email'=>$b['email'],'pending'=>$b['status']==='pending','token'=>$b['status']==='pending'?null:$result['token']];
    go('/?page=gebucht');
}
if(in_array($action,['booking_confirm','booking_cancel','booking_rebook'],true)){
    $page='buchung';
    $token=field('t','',64);$_GET['t']=$token;
    $bookings->limit('manage:'.$ip,30,900);
    if($action==='booking_confirm'){
        $result=$bookings->confirm($token);
        if($result['changed']){$bookingMail->confirmed($result['booking'],$token);$bookingMail->notifyAdmin('Neue Buchung',$result['booking']);}
        $_SESSION['flash']='Deine Buchung ist bestätigt. Du erhältst eine Bestätigung per E-Mail.';
        go('/?page=buchung&t='.$token);
    }
    if($action==='booking_cancel'){
        $b=$bookings->cancel($token);
        $bookingMail->cancelled($b,'self');$bookingMail->notifyAdmin('Stornierung',$b);
        $_SESSION['flash']='Deine Buchung wurde storniert. Du erhältst eine Bestätigung per E-Mail.';
        go('/?page=buchung&t='.$token);
    }
    $code=field('booking_code','',128);
    $result=$bookings->rebook($token,number('target',1,PHP_INT_MAX),function(array $target)use($code){if($target['code_hash'])booking_check_code($target,$code);});
    $bookingMail->confirmed($result['booking'],$result['token'],true);$bookingMail->notifyAdmin('Umbuchung',$result['booking']);
    $_SESSION['flash']='Umgebucht auf „'.$result['booking']['title'].'“. Du erhältst eine neue E-Mail mit einem neuen Link, der bisherige gilt nicht mehr.';
    go('/?page=buchung&t='.$result['token']);
}
