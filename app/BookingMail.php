<?php
declare(strict_types=1);
namespace Ausbildung;
use DateTimeImmutable;
use DateTimeZone;

/** Texts of all booking mails, with calendar attachment. Links are built from the fixed public address. */
final class BookingMail
{
    public function __construct(private Mailer $mailer,private Settings $settings,private Bookings $bookings,private string $baseUrl) {}
    public function link(string $token): string { return $this->baseUrl.'/?page=buchung&t='.$token; }
    private function details(array $b): string {
        return 'Termin: '.$b['title']."\nWann: ".Bookings::when($b).($b['location']!==''?"\nOrt: ".$b['location']:'')."\nName: ".$b['name']."\nFeuerwehr: ".$b['department'];
    }
    private function deadline(array $b): string { return date('d.m.Y, H:i',$this->bookings->changeDeadline($b)).' Uhr'; }
    private function footer(): string { return "\n\n".trim($this->settings->get('mail_signature'))."\n\n-- \nDiese Nachricht wurde automatisch versendet."; }
    private function manage(array $b,string $token): string {
        return "\n\nStornieren oder umbuchen (bis ".$this->deadline($b)."):\n".$this->link($token)."\nDer Link ist persönlich. Bitte nicht weitergeben.";
    }
    public function confirmed(array $b,string $token,bool $rebooked=false): void {
        $intro=$rebooked?'deine Buchung wurde umgebucht. Es gilt jetzt dieser Termin:':'deine Buchung ist bestätigt.';
        $extra=trim($this->settings->get('booking_confirm_text'));
        $text='Hallo '.$b['name'].",\n\n".$intro."\n\n".$this->details($b).($b['description']!==''?"\n\n".$b['description']:'').($extra!==''?"\n\n".$extra:'')
            .$this->manage($b,$token).($rebooked?"\nDer Link aus der vorherigen E-Mail gilt nicht mehr.":'')."\n\nIm Anhang findest du den Termin für deinen Kalender.".$this->footer();
        $this->mailer->queue($b['email'],($rebooked?'Umbuchung bestätigt: ':'Buchung bestätigt: ').$b['title'].' am '.date('d.m.Y',strtotime($b['starts_at'])),$text,$this->ics($b),$rebooked?'rebooked':'confirmed');
    }
    public function pending(array $b,string $token): void {
        $minutes=max(5,$this->settings->int('booking_optin_minutes'));
        $text='Hallo '.$b['name'].",\n\nbitte bestätige deine Buchung innerhalb von ".$minutes." Minuten über diesen Link:\n".$this->link($token)
            ."\n\n".$this->details($b)."\n\nOhne Bestätigung verfällt der Platz automatisch. Falls du nichts gebucht hast, kannst du diese E-Mail ignorieren.".$this->footer();
        $this->mailer->queue($b['email'],'Bitte Buchung bestätigen: '.$b['title'],$text,null,'pending');
    }
    public function cancelled(array $b,string $reason): void {
        $why=['self'=>'deine Buchung wurde wie gewünscht storniert.','admin'=>'deine Buchung wurde durch die Ausbildungsleitung storniert.','event'=>'der folgende Termin wurde leider abgesagt. Deine Buchung ist damit hinfällig.'][$reason];
        $text='Hallo '.$b['name'].",\n\n".$why."\n\n".$this->details($b).$this->footer();
        $this->mailer->queue($b['email'],($reason==='event'?'Termin abgesagt: ':'Stornierung: ').$b['title'].' am '.date('d.m.Y',strtotime($b['starts_at'])),$text,$this->ics($b,true),'cancelled');
    }
    /** Optional notice to the Ausbildungsleitung (Einstellungen › Benachrichtigung). */
    public function notifyAdmin(string $what,array $b): void {
        $to=$this->settings->get('booking_notify_email');
        if(!$this->settings->on('booking_notify_enabled')||!Mailer::validAddress($to))return;
        $event=$this->bookings->event((int)$b['event_id']);
        $text=$what."\n\n".$this->details($b)."\nE-Mail: ".$b['email']."\n\nBelegung: ".($event?$event['taken'].' von '.$event['capacity']:'–')."\n".$this->baseUrl.'/?page=booking-event&id='.(int)$b['event_id'].$this->footer();
        $this->mailer->queue($to,$what.': '.$b['title'],$text,null,'notify');
    }
    public function ics(array $b,bool $cancel=false): string {
        $utc=new DateTimeZone('UTC');
        $start=new DateTimeImmutable($b['starts_at']);
        $end=$b['ends_at']?new DateTimeImmutable($b['ends_at']):$start->modify('+2 hours');
        $f=fn(DateTimeImmutable $d)=>$d->setTimezone($utc)->format('Ymd\THis\Z');
        $host=parse_url($this->baseUrl,PHP_URL_HOST)?:'ausbildung';
        $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Ausbildung VG Selters//Terminbuchung//DE','METHOD:PUBLISH','BEGIN:VEVENT',
            'UID:booking-'.(int)$b['id'].'@'.$host,'DTSTAMP:'.$f(new DateTimeImmutable()),'DTSTART:'.$f($start),'DTEND:'.$f($end),
            'SUMMARY:'.self::escape(($cancel?'ABGESAGT: ':'').$b['title']),'SEQUENCE:'.(int)$b['version'],'STATUS:'.($cancel?'CANCELLED':'CONFIRMED')];
        if($b['location']!=='')$lines[]='LOCATION:'.self::escape($b['location']);
        if($b['description']!=='')$lines[]='DESCRIPTION:'.self::escape($b['description']);
        $lines[]='END:VEVENT';$lines[]='END:VCALENDAR';
        return implode("\r\n",array_map([self::class,'fold'],$lines))."\r\n";
    }
    private static function escape(string $text): string { return str_replace(["\\",";",",","\r\n","\n"],["\\\\","\\;","\\,","\\n","\\n"],$text); }
    private static function fold(string $line): string {
        $out='';
        while(strlen($line)>75){
            $cut=75;while($cut>0&&(ord($line[$cut])&0xC0)===0x80)$cut--;
            $out.=substr($line,0,$cut)."\r\n ";$line=substr($line,$cut);
        }
        return $out.$line;
    }
}
