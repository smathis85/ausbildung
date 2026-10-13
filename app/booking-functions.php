<?php
declare(strict_types=1);
// Booking code checks shared by the public booking pages and actions.
use Ausbildung\Settings;
/** An event with its own code always asks for it; otherwise the general code unless already entered in this session. */
function booking_code_needed(array $event): bool {
    global $appSettings;
    if($event['code_hash'])return true;
    return $appSettings->on('booking_code_required')&&($_SESSION['booking_unlocked']??null)!==$appSettings->int('booking_code_version');
}
function booking_check_code(array $event,string $code): void {
    global $appSettings,$bookings;
    $bookings->limit('code:'.($_SERVER['REMOTE_ADDR']??'unknown'),$appSettings->int('booking_rate_codes'),900);
    $hash=$event['code_hash']?:$appSettings->get('booking_code_hash');
    if(!Settings::codeMatches($hash,$code))throw new RuntimeException('Der Buchungscode stimmt nicht.');
    if(!$event['code_hash'])$_SESSION['booking_unlocked']=$appSettings->int('booking_code_version');
}
/** The list of events is hidden behind the general code when configured so. */
function booking_list_locked(): bool {
    global $appSettings;
    return $appSettings->on('booking_code_required')&&$appSettings->on('booking_list_needs_code')&&($_SESSION['booking_unlocked']??null)!==$appSettings->int('booking_code_version');
}
/** Bookable events shown directly on the start page and below the participant login. Empty when booking is off. */
function public_booking_card(): string {
    global $appSettings,$bookings;
    if(!$appSettings->on('booking_enabled'))return '';
    $html='<section class="card auth"><h2>Termine buchen</h2>';
    if(booking_list_locked())return $html.'<p>Für die Buchung brauchst du den Buchungscode deiner Feuerwehr oder der Ausbildungsleitung.</p><a class="button" href="/?page=termine">Zu den Terminen</a></section>';
    $events=$bookings->publicEvents();
    if(!$events)return $html.'<p>Derzeit sind keine Termine zur Buchung freigegeben.</p><p><a href="/?page=termine">Zu den Terminen</a></p></section>';
    $html.='<p>Freie Plätze ohne Konto buchen. Die Bestätigung kommt per E-Mail.</p><ul class="booking-list booking-teaser">';
    foreach($events as $e){
        $free=$bookings->free($e);$open=$bookings->bookingOpen($e);$url='/?page=termin&amp;id='.(int)$e['id'];
        $html.='<li><div><strong><a href="'.$url.'">'.h($e['title']).'</a></strong><p>'.h(Ausbildung\Bookings::when($e)).($e['location']!==''?' · '.h($e['location']):'').'</p></div><div>'
            .(!$open?'<span class="badge">Buchung geschlossen</span>':($free?'<span class="badge ready">Noch '.$free.' frei</span>':'<span class="badge cancelled">Ausgebucht</span>'))
            .($open&&$free?' <a class="button" href="'.$url.'">Buchen</a>':'').'</div></li>';
    }
    return $html.'</ul><p><a href="/?page=termine">Zu den Terminen</a></p></section>';
}
