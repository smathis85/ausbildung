<?php
declare(strict_types=1);
// Terminbuchung with synthetic data: seat limit, duplicates, per-address limit, tokens, cancel, rebook, double opt-in, purge, mails and calendar file.
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/Settings.php';
require __DIR__.'/../app/Mailer.php';
require __DIR__.'/../app/Bookings.php';
require __DIR__.'/../app/BookingMail.php';
use Ausbildung\{Store,Settings,Mailer,Bookings,BookingMail};
date_default_timezone_set('Europe/Berlin');
function check(bool $ok,string $what): void { if(!$ok){fwrite(STDERR,"FAIL: $what\n");exit(1);} }
function fails(callable $fn,string $text,string $what): void {
    try{$fn();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$text),$what.' ('.$e->getMessage().')');return;}
    check(false,$what.' (no error)');
}
$dir=sys_get_temp_dir().'/training-booking-'.bin2hex(random_bytes(4));mkdir($dir,0700);
try{
    $s=new Store($dir.'/training.sqlite');$s->schema();$s->schema();
    $settings=new Settings($s);$mailer=new Mailer($s,$settings,$dir.'/mail.key');$b=new Bookings($s,$settings);
    $mail=new BookingMail($mailer,$settings,$b,'https://ausbildung-dev.example');
    check($settings->get('booking_enabled')==='0'&&$settings->int('booking_max_per_email')===3,'defaults');
    fails(fn()=>$settings->save(['booking_enabled'=>'1'],99,'x'),'inzwischen geändert','stale settings version');
    $settings->save(['booking_code_hash'=>Settings::codeHash('Buchen-2026')],1,'test');
    check(Settings::codeMatches($settings->get('booking_code_hash'),'Buchen-2026')&&!Settings::codeMatches($settings->get('booking_code_hash'),'falsch'),'code hash');
    check(!str_contains(json_encode($s->rows('SELECT * FROM audit')),'Buchen-2026'),'no code in audit');
    // SMTP password is encrypted at rest.
    $enc=$mailer->encrypt('Geheim-Passwort');check($enc!==''&&!str_contains($enc,'Geheim')&&$mailer->decrypt($enc)==='Geheim-Passwort','password encryption');
    check(substr(sprintf('%o',fileperms($dir.'/mail.key')),-3)==='600','key file private');

    $day=date('Y-m-d',strtotime('+10 days'));$later=date('Y-m-d',strtotime('+20 days'));
    $base=['description'=>"Zeile 1\nZeile 2",'location'=>'Gerätehaus Selters, Halle','end_date'=>'','end'=>'21:30','code'=>'','visible'=>true,'remove_code'=>false];
    fails(fn()=>$b->saveEvent(0,0,['title'=>'X','date'=>$day,'start'=>'19:00','capacity'=>0]+$base),'zwischen 1 und 1000','capacity validation');
    fails(fn()=>$b->saveEvent(0,0,['title'=>'X','date'=>$day,'start'=>'19:00','end'=>'18:00','capacity'=>5]+$base),'nach dem Beginn','end after start');
    $e1=$b->saveEvent(0,0,['title'=>'Atemschutz; Übung, Teil 1','date'=>$day,'start'=>'19:00','capacity'=>2]+$base);
    $e2=$b->saveEvent(0,0,['title'=>'Ersatztermin','date'=>$later,'start'=>'19:00','capacity'=>5]+$base);
    $r1=$b->book($e1,'Erika Beispiel','ff selters','Erika@Example.org');
    check($r1['booking']['status']==='confirmed'&&$r1['booking']['department']==='Selters'&&Bookings::validToken($r1['token']),'confirmed booking');
    check($s->one('SELECT token_hash FROM bookings WHERE id=?',[$r1['booking']['id']])['token_hash']===hash('sha256',$r1['token']),'only token hash stored');
    fails(fn()=>$b->book($e1,'Erika Beispiel','Selters','erika@example.org'),'bereits eine Buchung','duplicate address per event');
    fails(fn()=>$b->book($e1,'X','Selters','x@example.org'),'Vor- und Nachnamen','name validation');
    fails(fn()=>$b->book($e1,'Max Muster','Selters',"x@example.org\r\nBcc: y@example.org"),'gültige E-Mail','header injection');
    $r2=$b->book($e1,'Max Muster','Maxsain','max@example.org');
    fails(fn()=>$b->book($e1,'Tina Test','Goddert','tina@example.org'),'ausgebucht','seat limit');
    check((int)$b->event($e1)['taken']===2,'taken count');
    // Per-address limit across events.
    $settings->save(['booking_max_per_email'=>'1'],2,'test');
    fails(fn()=>$b->book($e2,'Erika Beispiel','Selters','erika@example.org'),'Mehr sind nicht möglich','max per address');
    $settings->save(['booking_max_per_email'=>'3'],3,'test');
    // Capacity cannot drop below booked seats.
    fails(fn()=>$b->saveEvent($e1,1,['title'=>'A','date'=>$day,'start'=>'19:00','capacity'=>1]+$base),'bereits 2 Plätze','capacity below taken');
    // Cancel frees the seat; the link keeps showing the cancelled booking.
    $c=$b->cancel($r2['token']);check($c['status']==='cancelled'&&(int)$b->event($e1)['taken']===1,'cancel frees seat');
    fails(fn()=>$b->cancel($r2['token']),'bereits storniert','double cancel');
    check($b->byToken('ungueltig')===null&&$b->byToken(str_repeat('A',43))===null,'invalid tokens');
    // Rebook rotates the token.
    $rb=$b->rebook($r1['token'],$e2,fn()=>null);
    check((int)$rb['booking']['event_id']===$e2&&$b->byToken($r1['token'])===null&&$b->byToken($rb['token'])!==null,'rebook rotates token');
    check((int)$b->event($e1)['taken']===0&&(int)$b->event($e2)['taken']===1,'rebook moves seat');
    fails(fn()=>$b->rebook($rb['token'],$e2,fn()=>null),'keine Buchung möglich','rebook to same event');
    fails(fn()=>$b->rebook($rb['token'],$e1,function(){throw new RuntimeException('Der Buchungscode stimmt nicht.');}),'Buchungscode','rebook code check');
    // Change deadline.
    $settings->save(['booking_cancel_hours'=>'720'],4,'test');
    fails(fn()=>$b->cancel($rb['token']),'nicht mehr möglich','cancel deadline');
    $settings->save(['booking_cancel_hours'=>'0','booking_close_hours'=>'720'],5,'test');
    fails(fn()=>$b->book($e1,'Tina Test','Goddert','tina@example.org'),'keine Buchung','booking deadline');
    $admin=$b->book($e1,'Tina Test','Goddert','tina@example.org',true);check($admin['booking']['source']==='admin','admin may book after deadline');
    $settings->save(['booking_close_hours'=>'0','booking_double_optin'=>'1'],6,'test');
    // Double opt-in: pending seat counts until it expires.
    $p=$b->book($e1,'Paul Pending','Herschbach','paul@example.org');
    check($p['booking']['status']==='pending'&&(int)$b->event($e1)['taken']===2,'pending holds seat');
    $conf=$b->confirm($p['token']);check($conf['changed']&&$conf['booking']['status']==='confirmed','confirm');
    check(!$b->confirm($p['token'])['changed'],'confirm idempotent');
    $b->cancelByAdmin((int)$p['booking']['id']);
    $q=$b->book($e1,'Quentin Quick','Maroth','q@example.org');
    $s->run('UPDATE bookings SET expires_at=? WHERE id=?',[time()-1,$q['booking']['id']]);
    check($b->byToken($q['token'])===null&&(int)$b->event($e1)['taken']===1,'expired pending frees seat');
    // Event cancellation informs confirmed bookings only.
    $affected=$b->cancelEvent($e1);check(count($affected)===1&&$affected[0]['name']==='Tina Test','cancel event');
    fails(fn()=>$b->book($e1,'Neu Person','Selters','neu@example.org',true),'keine Buchung','no booking on cancelled event');
    // Mails: links, deadlines, calendar file with escaping and folding.
    $found=$b->find((int)$rb['booking']['id']);
    $mail->confirmed($found,$rb['token'],true);
    $m=$s->one("SELECT * FROM mail_outbox WHERE kind='rebooked'");
    check(str_contains($m['body'],'https://ausbildung-dev.example/?page=buchung&t='.$rb['token'])&&str_contains($m['body'],'gilt nicht mehr')&&str_starts_with($m['subject'],'Umbuchung bestätigt'),'rebook mail');
    check(str_contains($m['ics'],"SUMMARY:Ersatztermin")&&str_contains($m['ics'],'LOCATION:Gerätehaus Selters\, Halle')&&str_contains($m['ics'],'DESCRIPTION:Zeile 1\nZeile 2'),'ics escaping');
    foreach(explode("\r\n",$m['ics'])as$line)check(strlen($line)<=76,'ics folding');
    $raw=(new ReflectionClass($mailer))->getMethod('message')->invoke($mailer,'a@example.org','Ümläut',$m['body'],$m['ics']);
    check(str_contains($raw,'Subject: =?UTF-8?B?')&&str_contains($raw,'text/calendar')&&!preg_match("/(?<!\r)\n/",$raw),'mime message');
    $mail->notifyAdmin('Neue Buchung',$found);check(!$s->one("SELECT id FROM mail_outbox WHERE kind='notify'"),'no notify when disabled');
    $settings->save(['booking_notify_enabled'=>'1','booking_notify_email'=>'leitung@example.org'],7,'test');
    $mail->notifyAdmin('Neue Buchung',$found);check((bool)$s->one("SELECT id FROM mail_outbox WHERE kind='notify' AND recipient='leitung@example.org'"),'notify');
    // Without SMTP settings nothing is sent and nothing fails.
    check($mailer->flush()===['sent'=>0,'failed'=>0]&&$mailer->status()['open']>0,'queue waits for configuration');
    // Purge removes bookings of old events only.
    $s->run('UPDATE booking_events SET starts_at=?,ends_at=NULL WHERE id=?',[date('Y-m-d H:i',time()-100*86400),$e1]);
    $before=(int)$s->one('SELECT COUNT(*) n FROM bookings WHERE event_id=?',[$e2])['n'];
    check($b->purge()>0&&!$s->one('SELECT id FROM bookings WHERE event_id=?',[$e1])&&(int)$s->one('SELECT COUNT(*) n FROM bookings WHERE event_id=?',[$e2])['n']===$before,'purge');
    // Rate limit.
    for($i=0;$i<3;$i++)$b->limit('t:1',3,60);
    fails(fn()=>$b->limit('t:1',3,60),'Zu viele','rate limit');
    $s->run('UPDATE booking_rate SET started=?',[time()-61]);$b->limit('t:1',3,60);
    check(in_array('Selters',$b->departments(),true),'departments');
    echo "PASS: settings, code hash, encryption, seat limit, duplicates, address limit, deadlines, cancel, rebook, double opt-in, event cancel, mails, calendar, purge and rate limit\n";
}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
