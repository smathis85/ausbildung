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
