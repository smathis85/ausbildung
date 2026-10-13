<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;

/**
 * Outgoing mail: messages land in mail_outbox first and are then sent via SMTP (bin/mail-queue.php retries).
 * Plain socket SMTP because proc_open/popen are disabled in the PHP pool and the app has no Composer.
 */
final class Mailer
{
    public const MAX_ATTEMPTS=5;
    public bool $queued=false;
    public function __construct(private Store $store,private Settings $settings,private string $keyFile) {}
    public function configured(): bool {
        $c=$this->settings->all();
        return $c['smtp_host']!==''&&$c['mail_from']!=='';
    }
    private function key(): string {
        if(!is_file($this->keyFile)){
            $fh=@fopen($this->keyFile,'x');
            if($fh){fwrite($fh,sodium_bin2hex(sodium_crypto_secretbox_keygen()));fclose($fh);chmod($this->keyFile,0600);}
        }
        $key=sodium_hex2bin(trim((string)file_get_contents($this->keyFile)));
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Schlüsseldatei für E-Mail-Zugang ungültig.');
        return $key;
    }
    public function encrypt(string $plain): string {
        if($plain==='')return '';
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key()));
    }
    public function decrypt(string $cipher): string {
        if($cipher==='')return '';
        $raw=base64_decode($cipher,true);
        $plain=$raw!==false&&strlen($raw)>SODIUM_CRYPTO_SECRETBOX_NONCEBYTES?sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key()):false;
        if($plain===false)throw new RuntimeException('SMTP-Passwort kann nicht entschlüsselt werden. Bitte unter Einstellungen neu eingeben.');
        return $plain;
    }
    public static function validAddress(string $email): bool {
        return mb_strlen($email)<=254&&!preg_match('/[\r\n\s<>,;"]/',$email)&&filter_var($email,FILTER_VALIDATE_EMAIL)!==false;
    }
    public function queue(string $to,string $subject,string $text,?string $ics=null,string $kind=''): int {
        if(!self::validAddress($to))throw new RuntimeException('Ungültige E-Mail-Adresse.');
        $this->store->run('INSERT INTO mail_outbox(recipient,subject,body,ics,kind,created_at,next_try) VALUES(?,?,?,?,?,?,?)',[$to,$subject,$text,$ics,$kind,time(),time()]);
        $this->queued=true;
        return (int)$this->store->db->lastInsertId();
    }
    /** Sends due messages. Each row is claimed first so two runs never send the same mail twice. */
    public function flush(int $limit=20): array {
        $result=['sent'=>0,'failed'=>0];
        if(!$this->configured())return $result;
        $this->store->run("UPDATE mail_outbox SET status='pending' WHERE status='sending' AND claimed_at<?",[time()-600]);
        foreach($this->store->rows("SELECT id FROM mail_outbox WHERE status='pending' AND next_try<=? ORDER BY id LIMIT ?",[time(),$limit])as$row){
            if(!$this->store->run("UPDATE mail_outbox SET status='sending',claimed_at=? WHERE id=? AND status='pending'",[time(),$row['id']]))continue;
            $m=$this->store->one('SELECT * FROM mail_outbox WHERE id=?',[$row['id']]);
            try{
                $this->send($m['recipient'],$m['subject'],$m['body'],$m['ics']);
                $this->store->run("UPDATE mail_outbox SET status='sent',sent_at=?,last_error='' WHERE id=?",[time(),$m['id']]);
                $result['sent']++;
            }catch(\Throwable $e){
                $attempts=(int)$m['attempts']+1;
                $this->store->run('UPDATE mail_outbox SET status=?,attempts=?,next_try=?,last_error=? WHERE id=?',[$attempts>=self::MAX_ATTEMPTS?'failed':'pending',$attempts,time()+300*$attempts,mb_substr($e->getMessage(),0,300),$m['id']]);
                $result['failed']++;
            }
        }
        return $result;
    }
    public function status(): array {
        $r=$this->store->one("SELECT SUM(status IN ('pending','sending')) open,SUM(status='failed') failed,MAX(CASE WHEN status='sent' THEN sent_at END) last_sent FROM mail_outbox");
        $error=$this->store->one("SELECT last_error FROM mail_outbox WHERE last_error<>'' ORDER BY id DESC LIMIT 1");
        return ['open'=>(int)($r['open']??0),'failed'=>(int)($r['failed']??0),'last_sent'=>$r['last_sent']?(int)$r['last_sent']:null,'last_error'=>$error['last_error']??''];
    }
    public function retryFailed(): int { return $this->store->run("UPDATE mail_outbox SET status='pending',attempts=0,next_try=? WHERE status='failed'",[time()]); }
    /** Sent mails keep personal data; remove them after 30 days, failed ones after the same time. */
    public function purge(): int { return $this->store->run("DELETE FROM mail_outbox WHERE status IN ('sent','failed') AND created_at<?",[time()-30*86400]); }

    public static function header(string $text): string {
        $text=trim(preg_replace('/[\r\n]+/',' ',$text));
        return preg_match('/^[\x20-\x7e]*$/',$text)?$text:'=?UTF-8?B?'.base64_encode($text).'?=';
    }
    public function message(string $to,string $subject,string $text,?string $ics): string {
        $c=$this->settings->all();
        $from=$c['mail_from'];
        $domain=substr(strrchr($from,'@')?:'@localhost',1);
        $headers=['Date: '.date(DATE_RFC2822),'From: '.($c['mail_from_name']!==''?self::header($c['mail_from_name']).' ':'').'<'.$from.'>','To: <'.$to.'>','Subject: '.self::header($subject),
            'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>','MIME-Version: 1.0','Auto-Submitted: auto-generated'];
        if($c['mail_reply_to']!==''&&self::validAddress($c['mail_reply_to']))$headers[]='Reply-To: <'.$c['mail_reply_to'].'>';
        $text=preg_replace("/\r\n|\r|\n/","\r\n",$text);
        $plain="Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($text);
        if($ics===null)return implode("\r\n",$headers)."\r\n".$plain."\r\n";
        $boundary='b'.bin2hex(random_bytes(12));
        $headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';
        return implode("\r\n",$headers)."\r\n\r\n--".$boundary."\r\n".$plain."\r\n--".$boundary."\r\n"
            ."Content-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"termin.ics\"\r\nContent-Disposition: attachment; filename=\"termin.ics\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($ics),76,"\r\n")."--".$boundary."--\r\n";
    }
    public function send(string $to,string $subject,string $text,?string $ics=null): void {
        if(!$this->configured())throw new RuntimeException('E-Mail-Versand ist noch nicht eingerichtet.');
        if(!self::validAddress($to))throw new RuntimeException('Ungültige Empfängeradresse.');
        $c=$this->settings->all();
        if(!self::validAddress($c['mail_from']))throw new RuntimeException('Absenderadresse ungültig.');
        $host=$c['smtp_host'];$port=(int)$c['smtp_port'];$security=$c['smtp_security'];
        // Unencrypted SMTP only to a relay on this machine.
        if($security==='none'&&!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Unverschlüsselter Versand ist nur an einen lokalen Mailserver erlaubt.');
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
        $socket=@stream_socket_client(($security==='tls'?'tls://':'tcp://').$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
        if(!$socket)throw new RuntimeException('Mailserver nicht erreichbar ('.$host.':'.$port.'): '.$errstr);
        stream_set_timeout($socket,20);
        try{
            $this->expect($socket,[220]);
            $ehlo=$this->command($socket,'EHLO '.(gethostname()?:'localhost'),[250]);
            if($security==='starttls'){
                $this->command($socket,'STARTTLS',[220]);
                if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))throw new RuntimeException('TLS-Verbindung zum Mailserver fehlgeschlagen.');
                $ehlo=$this->command($socket,'EHLO '.(gethostname()?:'localhost'),[250]);
            }
            if($c['smtp_user']!==''){
                $password=$this->decrypt($c['smtp_password_enc']);
                if(preg_match('/AUTH[ =][^\r\n]*PLAIN/i',$ehlo))$this->command($socket,'AUTH PLAIN '.base64_encode("\0".$c['smtp_user']."\0".$password),[235],'AUTH PLAIN ***');
                else{$this->command($socket,'AUTH LOGIN',[334]);$this->command($socket,base64_encode($c['smtp_user']),[334],'***');$this->command($socket,base64_encode($password),[235],'***');}
            }
            $this->command($socket,'MAIL FROM:<'.$c['mail_from'].'>',[250]);
            $this->command($socket,'RCPT TO:<'.$to.'>',[250,251]);
            $this->command($socket,'DATA',[354]);
            $data=preg_replace('/^\./m','..',$this->message($to,$subject,$text,$ics));
            $this->command($socket,rtrim($data,"\r\n")."\r\n.",[250]);
            @fwrite($socket,"QUIT\r\n");
        }finally{fclose($socket);}
    }
    private function command($socket,string $line,array $codes,?string $shown=null): string {
        if(fwrite($socket,$line."\r\n")===false)throw new RuntimeException('Verbindung zum Mailserver unterbrochen.');
        return $this->expect($socket,$codes,$shown??explode(' ',$line)[0]);
    }
    private function expect($socket,array $codes,string $after='Verbindung'): string {
        $response='';
        while(($line=fgets($socket,2048))!==false){
            $response.=$line;
            if(strlen($line)<4||$line[3]===' ')break;
        }
        $code=(int)substr($response,0,3);
        if(!in_array($code,$codes,true)){
            $meta=stream_get_meta_data($socket);
            throw new RuntimeException($meta['timed_out']?'Mailserver antwortet nicht (Zeitüberschreitung).':'Mailserver lehnt '.$after.' ab: '.mb_substr(trim(preg_replace('/\s+/',' ',$response)),0,200));
        }
        return $response;
    }
}
