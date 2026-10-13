<?php
declare(strict_types=1);
namespace Ausbildung;
use DateTimeImmutable;
use RuntimeException;

/**
 * Public appointment booking (Terminbuchung): bookable events with a seat limit, bookings without password.
 * Each booking has a secret management link (only its SHA-256 is stored) for cancelling and rebooking.
 * Bookers are not participants of the Ausbildung and never see any training data.
 */
final class Bookings
{
    public const ACTIVE="(b.status='confirmed' OR (b.status='pending' AND b.expires_at>%d))";
    public function __construct(private Store $store,private Settings $settings) {}
    private static function active(string $alias='b'): string { return str_replace('b.',$alias.'.',sprintf(self::ACTIVE,time())); }

    public static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    public static function validToken(string $token): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{43}$/',$token); }
    public static function normalizeEmail(string $email): string { return mb_strtolower(trim($email),'UTF-8'); }
    public static function at(string $value): int { return (new DateTimeImmutable($value))->getTimestamp(); }
    public static function when(array $e): string {
        $days=['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        $start=new DateTimeImmutable($e['starts_at']);$end=$e['ends_at']?new DateTimeImmutable($e['ends_at']):null;
        $text=$days[(int)$start->format('w')].', '.$start->format('d.m.Y').', '.$start->format('H:i');
        if($end)$text.=$end->format('Y-m-d')===$start->format('Y-m-d')?' – '.$end->format('H:i'):' – '.$end->format('d.m.Y H:i');
        return $text.' Uhr';
    }

    public function events(bool $upcomingOnly=false): array {
        return $this->store->rows('SELECT e.*,(SELECT COUNT(*) FROM bookings b WHERE b.event_id=e.id AND '.self::active().') AS taken FROM booking_events e'
            .($upcomingOnly?' WHERE COALESCE(e.ends_at,e.starts_at)>=?':'').' ORDER BY e.starts_at,e.id',$upcomingOnly?[date('Y-m-d H:i')]:[]);
    }
    public function event(int $id): ?array {
        return $this->store->one('SELECT e.*,(SELECT COUNT(*) FROM bookings b WHERE b.event_id=e.id AND '.self::active().') AS taken FROM booking_events e WHERE e.id=?',[$id]);
    }
    /** Events the public may see: released, not cancelled, not yet started. */
    public function publicEvents(): array {
        return array_values(array_filter($this->events(true),fn($e)=>(int)$e['visible']&&!(int)$e['cancelled']&&self::at($e['starts_at'])>time()));
    }
    public function bookingOpen(array $e): bool {
        return (int)$e['visible']&&!(int)$e['cancelled']&&time()<self::at($e['starts_at'])-3600*$this->settings->int('booking_close_hours');
    }
    public function free(array $e): int { return max(0,(int)$e['capacity']-(int)$e['taken']); }
    public function changeDeadline(array $e): int { return self::at($e['starts_at'])-3600*$this->settings->int('booking_cancel_hours'); }

    public function saveEvent(int $id,int $version,array $in): int {
        $title=trim($in['title']);$description=trim(str_replace("\r\n","\n",$in['description']));$location=trim($in['location']);
        if($title===''||mb_strlen($title)>200)throw new RuntimeException('Bitte einen Titel (höchstens 200 Zeichen) angeben.');
        if(mb_strlen($description)>4000||mb_strlen($location)>200)throw new RuntimeException('Beschreibung oder Ort ist zu lang.');
        $start=self::dateTime($in['date'],$in['start']);
        if(!$start)throw new RuntimeException('Bitte Datum und Beginn angeben.');
        $end=$in['end']!==''?self::dateTime($in['end_date']!==''?$in['end_date']:$in['date'],$in['end']):null;
        if($in['end']!==''&&!$end)throw new RuntimeException('Ungültiges Ende.');
        if($end&&$end<=$start)throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        $capacity=(int)$in['capacity'];
        if($capacity<1||$capacity>1000)throw new RuntimeException('Die Teilnehmerzahl muss zwischen 1 und 1000 liegen.');
        $code=$in['code'];
        if($code!==''&&(mb_strlen($code)<6||mb_strlen($code)>128))throw new RuntimeException('Ein eigener Buchungscode muss zwischen 6 und 128 Zeichen lang sein.');
        return $this->store->transaction(function()use($id,$version,$title,$description,$location,$start,$end,$capacity,$code,$in){
            if($id){
                $old=$this->event($id);
                if(!$old||(int)$old['version']!==$version)throw new RuntimeException('Termin inzwischen geändert. Bitte neu laden.');
                if($capacity<(int)$old['taken'])throw new RuntimeException('Es sind bereits '.$old['taken'].' Plätze gebucht. Die Teilnehmerzahl kann nicht darunter liegen.');
                $hash=$in['remove_code']?null:($code!==''?Settings::codeHash($code):$old['code_hash']);
                $this->store->run('UPDATE booking_events SET title=?,description=?,location=?,starts_at=?,ends_at=?,capacity=?,visible=?,code_hash=?,version=version+1 WHERE id=?',[$title,$description,$location,$start,$end,$capacity,$in['visible']?1:0,$hash,$id]);
            }else{
                $this->store->run('INSERT INTO booking_events(title,description,location,starts_at,ends_at,capacity,visible,code_hash) VALUES(?,?,?,?,?,?,?,?)',[$title,$description,$location,$start,$end,$capacity,$in['visible']?1:0,$code!==''?Settings::codeHash($code):null]);
                $id=(int)$this->store->db->lastInsertId();
            }
            $this->store->audit('booking_event_saved','booking_event',$id);
            return $id;
        });
    }
    private static function dateTime(string $date,string $time): ?string {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time);
        return $d&&$d->format('Y-m-d H:i')===$date.' '.$time?$d->format('Y-m-d H:i'):null;
    }
    public function deleteEvent(int $id): void {
        $this->store->transaction(function()use($id){
            if($this->store->one('SELECT id FROM bookings b WHERE event_id=? AND '.self::active().' LIMIT 1',[$id]))throw new RuntimeException('Der Termin hat noch Buchungen. Bitte zuerst absagen.');
            $this->store->run('DELETE FROM bookings WHERE event_id=?',[$id]);
            if(!$this->store->run('DELETE FROM booking_events WHERE id=?',[$id]))throw new RuntimeException('Termin nicht gefunden.');
            $this->store->audit('booking_event_deleted','booking_event',$id);
        });
    }
    /** Cancels the event and every active booking. Returns the bookings that must be informed. */
    public function cancelEvent(int $id): array {
        return $this->store->transaction(function()use($id){
            $event=$this->event($id);
            if(!$event||(int)$event['cancelled'])throw new RuntimeException('Termin nicht gefunden oder bereits abgesagt.');
            $affected=$this->store->rows("SELECT * FROM bookings b WHERE event_id=? AND b.status='confirmed'",[$id]);
            $this->store->run("UPDATE bookings SET status='cancelled',cancelled_at=?,cancel_reason='event',version=version+1 WHERE event_id=? AND status IN ('pending','confirmed')",[time(),$id]);
            $this->store->run('UPDATE booking_events SET cancelled=1,version=version+1 WHERE id=?',[$id]);
            $this->store->audit('booking_event_cancelled','booking_event',$id,['bookings'=>count($affected)]);
            return $affected;
        });
    }

    /**
     * Books one seat inside a write transaction (BEGIN IMMEDIATE), so the seat limit holds under concurrency.
     * Returns the booking row plus the plain management token, which exists only in this response and the mail.
     */
    public function book(int $eventId,string $name,string $department,string $email,bool $admin=false): array {
        [$name,$department,$email]=self::person($name,$department,$email);
        return $this->store->transaction(function()use($eventId,$name,$department,$email,$admin){
            $this->store->run("DELETE FROM bookings WHERE status='pending' AND expires_at<=?",[time()]);
            $event=$this->event($eventId);
            if(!$event||(int)$event['cancelled']||(!$admin&&!$this->bookingOpen($event)))throw new RuntimeException('Für diesen Termin ist keine Buchung (mehr) möglich.');
            if($this->free($event)<1)throw new RuntimeException('Der Termin ist leider ausgebucht.');
            $norm=self::normalizeEmail($email);
            if($this->store->one('SELECT id FROM bookings b WHERE event_id=? AND email_norm=? AND '.self::active(),[$eventId,$norm]))throw new RuntimeException('Mit dieser E-Mail-Adresse besteht für diesen Termin bereits eine Buchung. Den Link zum Verwalten findest du in der Bestätigungsmail.');
            if(!$admin){
                $max=$this->settings->int('booking_max_per_email');
                $n=(int)$this->store->one('SELECT COUNT(*) n FROM bookings b JOIN booking_events e ON e.id=b.event_id WHERE b.email_norm=? AND e.cancelled=0 AND e.starts_at>=? AND '.self::active(),[$norm,date('Y-m-d H:i')])['n'];
                if($max>0&&$n>=$max)throw new RuntimeException('Mit dieser E-Mail-Adresse sind bereits '.$n.' Termine gebucht. Mehr sind nicht möglich.');
            }
            $pending=!$admin&&$this->settings->on('booking_double_optin');
            $token=self::token();
            $this->store->run('INSERT INTO bookings(event_id,name,department,email,email_norm,status,token_hash,created_at,confirmed_at,expires_at,source) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$eventId,$name,$department,$email,$norm,$pending?'pending':'confirmed',hash('sha256',$token),time(),$pending?null:time(),$pending?time()+60*max(5,$this->settings->int('booking_optin_minutes')):null,$admin?'admin':'public']);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->audit($admin?'booking_added_by_admin':'booking_created','booking',$id,['event'=>$eventId,'pending'=>$pending]);
            return ['booking'=>$this->find($id),'token'=>$token];
        });
    }
    public static function person(string $name,string $department,string $email): array {
        $name=trim(preg_replace('/\s+/u',' ',$name));$department=Store::canonicalDepartment($department);$email=trim($email);
        if(mb_strlen($name)<3||mb_strlen($name)>120||!preg_match('/\p{L}/u',$name))throw new RuntimeException('Bitte Vor- und Nachnamen angeben.');
        if(mb_strlen($department)<2||mb_strlen($department)>120)throw new RuntimeException('Bitte die Feuerwehr angeben.');
        if(!Mailer::validAddress($email))throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
        return [$name,$department,$email];
    }
    public function find(int $id): ?array {
        return $this->store->one('SELECT b.*,e.title,e.description,e.location,e.starts_at,e.ends_at,e.cancelled AS event_cancelled FROM bookings b JOIN booking_events e ON e.id=b.event_id WHERE b.id=?',[$id]);
    }
    public function byToken(string $token): ?array {
        if(!self::validToken($token))return null;
        $b=$this->store->one('SELECT id,status,expires_at FROM bookings WHERE token_hash=?',[hash('sha256',$token)]);
        if(!$b||($b['status']==='pending'&&(int)$b['expires_at']<=time()))return null;
        return $this->find((int)$b['id']);
    }
    public function forEvent(int $eventId): array {
        return $this->store->rows('SELECT b.*,'.self::active().' AS active FROM bookings b WHERE event_id=? ORDER BY active DESC,b.status,b.created_at,b.id',[$eventId]);
    }
    /** Active bookings can be changed until the configured deadline before the start. */
    public function changeable(array $b): bool {
        return in_array($b['status'],['confirmed','pending'],true)&&!(int)$b['event_cancelled']&&time()<$this->changeDeadline($b);
    }
    public function confirm(string $token): array {
        return $this->store->transaction(function()use($token){
            $b=$this->byToken($token);
            if(!$b)throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
            if($b['status']==='cancelled')throw new RuntimeException('Diese Buchung ist bereits storniert.');
            if($b['status']!=='pending')return ['booking'=>$b,'changed'=>false];
            $this->store->run("UPDATE bookings SET status='confirmed',confirmed_at=?,expires_at=NULL,version=version+1 WHERE id=? AND status='pending'",[time(),$b['id']]);
            $this->store->audit('booking_confirmed','booking',(int)$b['id']);
            return ['booking'=>$this->find((int)$b['id']),'changed'=>true];
        });
    }
    public function cancel(string $token): array {
        return $this->store->transaction(function()use($token){
            $b=$this->byToken($token);
            if(!$b)throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
            if(!$this->changeable($b))throw new RuntimeException($b['status']==='cancelled'?'Diese Buchung ist bereits storniert.':'Eine Stornierung über den Link ist nicht mehr möglich. Bitte wende dich an die Ausbildungsleitung.');
            $this->store->run("UPDATE bookings SET status='cancelled',cancelled_at=?,cancel_reason='self',version=version+1 WHERE id=?",[time(),$b['id']]);
            $this->store->audit('booking_cancelled','booking',(int)$b['id']);
            return $this->find((int)$b['id']);
        });
    }
    public function cancelByAdmin(int $id): array {
        return $this->store->transaction(function()use($id){
            $b=$this->find($id);
            if(!$b||!in_array($b['status'],['pending','confirmed'],true))throw new RuntimeException('Buchung nicht gefunden oder bereits storniert.');
            $this->store->run("UPDATE bookings SET status='cancelled',cancelled_at=?,cancel_reason='admin',version=version+1 WHERE id=?",[time(),$id]);
            $this->store->audit('booking_cancelled_by_admin','booking',$id);
            return $this->find($id);
        });
    }
    /** Targets for rebooking: other bookable events with a free seat. */
    public function rebookTargets(array $b): array {
        return array_values(array_filter($this->publicEvents(),fn($e)=>(int)$e['id']!==(int)$b['event_id']&&$this->bookingOpen($e)&&$this->free($e)>0));
    }
    /** Moves the booking to another event and rotates the management link; the old link stops working. */
    public function rebook(string $token,int $targetId,callable $codeCheck): array {
        return $this->store->transaction(function()use($token,$targetId,$codeCheck){
            $this->store->run("DELETE FROM bookings WHERE status='pending' AND expires_at<=?",[time()]);
            $b=$this->byToken($token);
            if(!$b)throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
            if(!$this->changeable($b))throw new RuntimeException('Eine Umbuchung über den Link ist nicht mehr möglich. Bitte wende dich an die Ausbildungsleitung.');
            $target=$this->event($targetId);
            if(!$target||(int)$target['id']===(int)$b['event_id']||!$this->bookingOpen($target))throw new RuntimeException('Für diesen Termin ist keine Buchung möglich.');
            if($this->free($target)<1)throw new RuntimeException('Der gewählte Termin ist inzwischen ausgebucht.');
            $codeCheck($target);
            if($this->store->one('SELECT id FROM bookings b WHERE event_id=? AND email_norm=? AND '.self::active(),[$targetId,$b['email_norm']]))throw new RuntimeException('Für den gewählten Termin besteht mit deiner E-Mail-Adresse bereits eine Buchung.');
            $new=self::token();
            $this->store->run('UPDATE bookings SET event_id=?,token_hash=?,version=version+1 WHERE id=?',[$targetId,hash('sha256',$new),$b['id']]);
            $this->store->audit('booking_rebooked','booking',(int)$b['id'],['from'=>(int)$b['event_id'],'to'=>$targetId]);
            return ['booking'=>$this->find((int)$b['id']),'token'=>$new,'from'=>$b];
        });
    }
    /** Deletes personal data some days after the event (Einstellungen › Löschfrist) and expired unconfirmed bookings. */
    public function purge(): int {
        $days=max(1,$this->settings->int('booking_retention_days'));
        $limit=date('Y-m-d H:i',time()-86400*$days);
        $n=$this->store->run("DELETE FROM bookings WHERE status='pending' AND expires_at<=?",[time()]);
        $n+=$this->store->run('DELETE FROM bookings WHERE event_id IN (SELECT id FROM booking_events WHERE COALESCE(ends_at,starts_at)<?)',[$limit]);
        if($n)$this->store->audit('bookings_purged','booking',null,['count'=>$n]);
        return $n;
    }
    /** Counts one attempt per key (hashed IP) and window; throws once the limit is reached. */
    public function limit(string $key,int $max,int $window): void {
        if($max<1)return;
        $key=hash('sha256',$key);
        $this->store->transaction(function()use($key,$max,$window){
            $this->store->run('DELETE FROM booking_rate WHERE started<?',[time()-86400]);
            $r=$this->store->one('SELECT * FROM booking_rate WHERE key=?',[$key]);
            if($r&&(int)$r['started']<time()-$window){$this->store->run('DELETE FROM booking_rate WHERE key=?',[$key]);$r=null;}
            if($r&&(int)$r['attempts']>=$max){http_response_code(429);throw new RuntimeException('Zu viele Versuche. Bitte später erneut versuchen.');}
            $this->store->run('INSERT INTO booking_rate(key,attempts,started) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=attempts+1',[$key,time()]);
        });
    }
    /** Fire brigades for the booking form: known names plus those of active participants. */
    public function departments(): array {
        $list=[];
        foreach(array_merge(Store::KNOWN_DEPARTMENTS,array_column($this->store->rows("SELECT DISTINCT TRIM(department) d FROM participants WHERE archived=0 AND TRIM(department)<>''"),'d'))as$d)$list[mb_strtolower($d)]=$d;
        $list=array_values($list);(new \Collator('de_DE'))->sort($list);
        return $list;
    }
}
