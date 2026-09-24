<?php
declare(strict_types=1);
require __DIR__.'/../app/Store.php';
use Ausbildung\Store;
date_default_timezone_set('Europe/Berlin');
umask(0077);
$dataDir=getenv('TRAINING_DATA_DIR')?:__DIR__.'/../private';
$local=getenv('TRAINING_LOCAL_TEST')==='1';
if(!$local && ($_SERVER['HTTPS']??'')!=='on') { http_response_code(400); exit('HTTPS erforderlich.'); }
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex, nofollow');
if(!$local) header('Strict-Transport-Security: max-age=31536000');
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
if(!is_dir($dataDir.'/sessions')) mkdir($dataDir.'/sessions',0700,true);
session_save_path($dataDir.'/sessions'); session_name('ausbildung_dev_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']); session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(32));
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function go(string $url): never { header('Location: '.$url,true,303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function field(string $name,string $default='',int $max=1000): string {
    $v=$_POST[$name]??$default;
    if(!is_string($v)||mb_strlen($v)>$max) throw new RuntimeException('Ungültige Eingabe: '.$name);
    return str_contains($name,'password') ? $v : trim($v);
}
function number(string $name,int $min,int $max,int $default=0): int {
    $v=filter_var($_POST[$name]??$default,FILTER_VALIDATE_INT);
    if($v===false||$v<$min||$v>$max) throw new RuntimeException('Ungültiger Zahlenwert: '.$name);
    return $v;
}
function day(string $name): ?string {
    $v=field($name,'',10); if($v==='')return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if(!$d||$d->format('Y-m-d')!==$v) throw new RuntimeException('Ungültiges Datum.');
    return $v;
}
function status(array $p): string {
    if($p['exam_date']||$p['exam_label']) return '<span class="badge passed">Prüfung bestanden</span>';
    if((int)$p['total']>=12) return '<span class="badge ready">12 Teilnahmen erreicht</span>';
    $missing=12-(int)$p['total'];
    return '<span class="badge">Noch '.$missing.' '.($missing===1?'Teilnahme':'Teilnahmen').'</span>';
}
function input(string $label,string $name,mixed $value='',string $type='text',string $extra=''): string {
    return '<label>'.h($label).'<input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.$extra.'></label>';
}
function head(string $title,bool $auth=true): void {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · Ausbildung</title><link rel="stylesheet" href="/assets/app.css"><script defer src="/assets/app.js"></script></head><body><header><a class="brand" href="/">Ausbildung <small>VG Selters · DEV</small></a>';
    if($auth) echo '<nav aria-label="Hauptnavigation"><a href="/">Übersicht</a><a href="/?page=lessons">Termine</a><a href="/?page=import">Importprüfung</a><a href="/?page=account">Zugang</a><form method="post">'.csrf().'<input type="hidden" name="action" value="logout"><button class="subtle">Abmelden</button></form></nav>';
    echo '</header><main><h1>'.h($title).'</h1>';
    if(isset($_SESSION['flash'])) { echo '<p class="notice" role="status">'.h($_SESSION['flash']).'</p>'; unset($_SESSION['flash']); }
}
function foot(): void { echo '</main><footer>Eigenständige Ausbildungsverwaltung · Testumgebung · Nur für deinen Zugang</footer></body></html>'; }
try {
    $s=new Store($dataDir.'/training.sqlite');
    $account=$s->one('SELECT * FROM accounts WHERE id=1');
    if(!$account) throw new RuntimeException('Ersteinrichtung fehlt.');
} catch(Throwable $e) { http_response_code(503); exit('Ausbildungsverwaltung wird vorbereitet. Bitte später erneut versuchen.'); }
$page=is_string($_GET['page']??null)?$_GET['page']:'dashboard';
$auth=isset($_SESSION['uid']) && (int)$_SESSION['uid']===1 && ($_SESSION['version']??null)===(int)$account['version'] && time()-($_SESSION['seen']??0)<7200 && time()-($_SESSION['created']??0)<43200;
if($auth) $_SESSION['seen']=time(); else unset($_SESSION['uid']);
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])) { http_response_code(403); throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte Seite neu laden.'); }
        $action=field('action','',40);
        if(in_array($action,['login','setup'],true)) {
            $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown'));
            $s->transaction(function()use($s,$key){
                $s->run('DELETE FROM rate_limits WHERE started<?',[time()-900]);
                $r=$s->one('SELECT * FROM rate_limits WHERE key=?',[$key]);
                if($r && (int)$r['attempts']>=10) { http_response_code(429); throw new RuntimeException('Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.'); }
                $s->run('INSERT INTO rate_limits(key,attempts,started) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=attempts+1',[$key,time()]);
            });
            $email=strtolower(field('email','',254)); $password=field('password','',256);
            if($action==='setup') {
                $code=field('setup_code','',100);
                if($email!==$account['email']||$account['password_hash']||!$account['setup_hash']||$account['setup_expires']<time()||!hash_equals($account['setup_hash'],hash('sha256',$code))) throw new RuntimeException('Einrichtungscode oder Zugangsdaten ungültig.');
                if(mb_strlen($password)<12 || $password!==field('password_confirmation','',256)) throw new RuntimeException('Mindestens 12 Zeichen verwenden und Passwort identisch bestätigen.');
                $s->transaction(function() use($s,$password,$account){
                    if(!$s->run('UPDATE accounts SET password_hash=?,setup_hash=NULL,setup_expires=NULL WHERE id=1 AND password_hash IS NULL AND setup_hash=?',[password_hash($password,PASSWORD_DEFAULT),$account['setup_hash']])) throw new RuntimeException('Zugang bereits eingerichtet.');
                    $s->audit('access_activated');
                });
                if(is_file($dataDir.'/setup-code.txt')) unlink($dataDir.'/setup-code.txt');
            } elseif($email!==$account['email']||!$account['password_hash']||!password_verify($password,$account['password_hash'])) {
                throw new RuntimeException('Anmeldung nicht möglich. Bitte Zugangsdaten prüfen.');
            }
            $s->run('DELETE FROM rate_limits WHERE key=?',[$key]);
            session_regenerate_id(true); $_SESSION=['uid'=>1,'version'=>(int)$account['version'],'seen'=>time(),'created'=>time(),'csrf'=>bin2hex(random_bytes(32))]; go('/');
        }
        if(!$auth) { http_response_code(401); throw new RuntimeException('Bitte zuerst anmelden.'); }
        if($action==='logout') { $_SESSION=[]; session_destroy(); setcookie(session_name(),'', ['expires'=>1,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']); go('/'); }
        if($action==='password') {
            if(!password_verify(field('current_password','',256),$account['password_hash'])) throw new RuntimeException('Aktuelles Passwort nicht korrekt.');
            $password=field('password','',256);
            if(mb_strlen($password)<12||$password!==field('password_confirmation','',256)) throw new RuntimeException('Mindestens 12 Zeichen verwenden und Passwort identisch bestätigen.');
            $s->transaction(function()use($s,$password){$s->run('UPDATE accounts SET password_hash=?,version=version+1 WHERE id=1',[password_hash($password,PASSWORD_DEFAULT)]);$s->audit('password_changed');});
            $_SESSION=[]; session_regenerate_id(true); go('/');
        }
        if($action==='attendance') {
            $id=number('id',1,PHP_INT_MAX);$version=number('version',1,PHP_INT_MAX);
            $selected=$_POST['present']??[]; if(!is_array($selected)) throw new RuntimeException('Ungültige Auswahl.');
            foreach($selected as $v) if(!ctype_digit((string)$v)) throw new RuntimeException('Ungültige Auswahl.');
            $s->saveAttendance($id,$version,array_values(array_unique(array_map('intval',$selected))));
            $_SESSION['flash']='Anwesenheiten gespeichert. Teilnahmezahlen sind aktualisiert.'; go('/?page=lesson&id='.$id);
        }
        if($action==='lesson') {
            $id=number('id',0,PHP_INT_MAX);$year=number('year',2010,2100);$date=day('date');$title=field('title','',200);$unit=number('unit',1,20,1);
            if($title===''||($date&&(int)substr($date,0,4)!==$year)) throw new RuntimeException('Titel und Jahr des Termins prüfen.');
            if(!$id&&!$date) throw new RuntimeException('Für neue Termine ist ein Datum erforderlich.');
            $s->transaction(function()use($s,&$id,$year,$date,$title,$unit){
                if($id) {
                    $old=$s->one('SELECT * FROM lessons WHERE id=?',[$id]);
                    if(!$old||(int)$old['year']!==$year) throw new RuntimeException('Jahr eines bestehenden Termins bleibt erhalten.');
                    if(!$s->run('UPDATE lessons SET date=?,title=?,unit=?,version=version+1 WHERE id=? AND version=?',[$date,$title,$unit,$id,number('version',1,PHP_INT_MAX)])) throw new RuntimeException('Termin inzwischen geändert. Bitte neu laden.');
                } else { $s->run('INSERT INTO lessons(year,date,title,unit) VALUES(?,?,?,?)',[$year,$date,$title,$unit]);$id=(int)$s->db->lastInsertId(); }
                $s->audit('lesson_saved','lesson',$id);
            });$_SESSION['flash']='Termin gespeichert.';go('/?page=lesson&id='.$id);
        }
        if($action==='person') {
            $id=number('id',0,PHP_INT_MAX); $year=number('year',2010,2100);
            $first=field('first_name','',100);$last=field('last_name','',100);$dept=field('department','',120);
            if(!$first||!$last||!$dept) throw new RuntimeException('Vorname, Nachname und Feuerwehr sind erforderlich.');
            $start=day('start_date');$course=field('course','',100);$exam=day('exam_date');$comment=field('comment','',3000);$reported=field('reported','',200);
            $adjustment=number('adjustment',-10000,10000);$reason=field('adjustment_reason','',500);
            if($adjustment!==0&&$reason==='') throw new RuntimeException('Bitte den Vortrag bzw. die Korrektur begründen.');
            $s->transaction(function()use($s,&$id,$year,$first,$last,$dept,$start,$course,$exam,$comment,$reported,$adjustment,$reason){
                if(!$id){
                    if($s->one('SELECT id FROM participants WHERE lower(first_name)=lower(?) AND lower(last_name)=lower(?) AND lower(department)=lower(?)',[$first,$last,$dept])) throw new RuntimeException('Diese Person ist bereits vorhanden. Über die Jahresübernahme oder bestehende Person ergänzen.');
                    $s->run('INSERT INTO participants(first_name,last_name,department) VALUES(?,?,?)',[$first,$last,$dept]);$id=(int)$s->db->lastInsertId();
                    $s->run('INSERT INTO enrollments(participant_id,year) VALUES(?,?)',[$id,$year]);
                }else{
                    if(!$s->run('UPDATE participants SET first_name=?,last_name=?,department=?,archived=?,version=version+1 WHERE id=? AND version=?',[$first,$last,$dept,isset($_POST['archived'])?1:0,$id,number('person_version',1,PHP_INT_MAX)])) throw new RuntimeException('Person inzwischen geändert. Bitte neu laden.');
                    $e=$s->one('SELECT * FROM enrollments WHERE participant_id=? AND year=?',[$id,$year]);
                    if(!$e||(int)$e['version']!==number('enrollment_version',1,PHP_INT_MAX)) throw new RuntimeException('Jahresdaten inzwischen geändert. Bitte neu laden.');
                }
                $s->run('UPDATE enrollments SET start_date=?,course=?,exam_date=?,exam_label=?,reported=?,comment=?,adjustment=?,adjustment_reason=?,version=version+1 WHERE participant_id=? AND year=?',[$start,$course,$exam,$exam??'',$reported,$comment,$adjustment,$reason,$id,$year]);
                if($s->total($id,$year)<0)throw new RuntimeException('Die Gesamtzahl darf nicht negativ sein.');
                $s->audit('participant_saved','participant',$id,['year'=>$year]);
            });$_SESSION['flash']='Teilnehmerdaten gespeichert.';go('/?page=person&id='.$id.'&year='.$year);
        }
        if($action==='year') {
            $year=number('year',2010,2100);$source=number('source_year',2010,2100);
            if($year<=$source)throw new RuntimeException('Das neue Jahr muss nach dem bisherigen Jahr liegen.');
            $n=$s->transaction(function()use($s,$year,$source){
                if($s->one('SELECT participant_id FROM enrollments WHERE year=? LIMIT 1',[$year])) throw new RuntimeException('Das Jahr wurde bereits angelegt.');
                $rows=$s->rows("SELECT e.* FROM enrollments e JOIN participants p ON p.id=e.participant_id WHERE e.year=? AND p.archived=0 AND e.exam_date IS NULL AND e.exam_label=''",[$source]);
                foreach($rows as $e)$s->run('INSERT INTO enrollments(participant_id,year,start_label,start_date,course) VALUES(?,?,?,?,?)',[$e['participant_id'],$year,$e['start_label'],$e['start_date'],$e['course']]);
                $s->audit('year_created','year',$year,['participants'=>count($rows),'source'=>$source]);return count($rows);
            });$_SESSION['flash']=$n.' aktive Teilnehmer ohne bestandene Prüfung ins neue Jahr übernommen. Teilnahmehistorie bleibt erhalten.';go('/?year='.$year);
        }
        throw new RuntimeException('Unbekannte Aktion.');
    } catch(PDOException $e) { http_response_code(500); $error='Die Daten konnten nicht gespeichert werden. Bitte Eingaben prüfen oder erneut versuchen.'; }
    catch(RuntimeException $e) { $error=$e->getMessage(); }
    catch(Throwable $e) { http_response_code(500);$error='Speichern nicht möglich. Es wurden keine unvollständigen Änderungen übernommen.'; }
}
if(!$auth) {
    $setup=$page==='setup'&&!$account['password_hash']; head($setup?'Zugang einrichten':'Willkommen',false);
    echo '<section class="auth card"><h2>'.($setup?'Dein persönlicher Zugang':'Ausbildungsverwaltung').'</h2><p>Geschützter Bereich für die Ausbildung der Feuerwehr VG Selters.</p>';
    if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
    echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="'.($setup?'setup':'login').'">'.input('E-Mail-Adresse','email','','email','required autocomplete="username"');
    if($setup) echo input('Einmaliger Einrichtungscode','setup_code','','password','required autocomplete="off"');
    echo input($setup?'Passwort festlegen (mindestens 12 Zeichen)':'Passwort','password','','password','required autocomplete="'.($setup?'new-password':'current-password').'"');
    if($setup) echo input('Passwort wiederholen','password_confirmation','','password','required autocomplete="new-password"');
    echo '<button>'.($setup?'Zugang aktivieren':'Anmelden').'</button></form>';
    if(!$account['password_hash']&&!$setup)echo '<p><a href="/?page=setup">Ersteinrichtung mit Einrichtungscode</a></p>';
    echo '</section>';foot();exit;
}
$years=array_map('intval',array_column($s->rows('SELECT DISTINCT year FROM enrollments UNION SELECT DISTINCT year FROM lessons ORDER BY year DESC'),'year'));
$year=filter_var($_GET['year']??($years[0]??date('Y')),FILTER_VALIDATE_INT); if(!$year||$year<2010||$year>2100)$year=(int)date('Y');
$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT)?:0;
$titles=['dashboard'=>'Ausbildungsübersicht','person'=>'Teilnehmer','lessons'=>'Termine & Anwesenheiten','lesson'=>'Anwesenheit erfassen','import'=>'Importprüfung','account'=>'Dein Zugang'];
if(!isset($titles[$page])&&$page!=='export') { http_response_code(404);$page='dashboard'; }
if($page==='export') {
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ausbildung-'.$year.'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Nachname','Vorname','Feuerwehr','Jahr','Teilnahmen im Jahr','Teilnahmen gesamt','Fehlend bis 12','Prüfung bestanden'],';','"','');
    foreach($s->participants($year) as $p){$row=[$p['last_name'],$p['first_name'],$p['department'],$year,$p['annual'],$p['total'],max(0,12-(int)$p['total']),$p['exam_date']?:$p['exam_label']];foreach($row as &$v)if(is_string($v)&&preg_match('/^[\s]*[=+@\-]/u',$v))$v="'".$v;unset($v);fputcsv($out,$row,';','"','');}fclose($out);exit;
}
head($titles[$page]); if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
echo '<p class="muted">Prüfungsvoraussetzung: mindestens <strong>12 Teilnahmen</strong>. Eine Einheit zählt als eine Teilnahme.</p>';
if(in_array($page,['dashboard','lessons'],true)) {
    echo '<form class="filters" method="get"><input type="hidden" name="page" value="'.h($page).'"><label>Ausbildungsjahr<select name="year">';
    foreach($years as $y)echo '<option '.($y===$year?'selected':'').'>'.$y.'</option>';
    echo '</select></label><button class="secondary">Jahr anzeigen</button></form>';
}
if($page==='dashboard') {
    $people=$s->participants($year);$active=array_filter($people,fn($p)=>!$p['archived']);$ready=count(array_filter($active,fn($p)=>$p['total']>=12&&!$p['exam_date']&&!$p['exam_label']));
    $passed=count(array_filter($people,fn($p)=>$p['exam_date']||$p['exam_label']));
    echo '<div class="stats"><section class="card"><span>Teilnehmer '.$year.'</span><strong>'.count($people).'</strong></section><section class="card"><span>12 Teilnahmen erreicht · noch ohne Prüfung</span><strong>'.$ready.'</strong></section><section class="card"><span>Prüfung bestanden</span><strong>'.$passed.'</strong></section></div>';
    echo '<section class="card"><div class="toolbar"><div><h2>Teilnehmer</h2><p class="muted">Gesamtstand bis einschließlich '.$year.'</p></div><div class="actions"><a class="button secondary" href="/?page=export&year='.$year.'">CSV exportieren</a><a class="button" href="/?page=person&year='.$year.'">Teilnehmer hinzufügen</a></div></div><div class="filters"><label>Suchen<input id="search" type="search" placeholder="Name oder Feuerwehr"></label><label>Feuerwehr<select id="department"><option value="">Alle Feuerwehren</option>';
    $deps=array_unique(array_column($people,'department'));sort($deps);foreach($deps as $d)echo '<option>'.h($d).'</option>';
    echo '</select></label><label>Status<select id="status"><option value="">Alle</option><option value="pending">Unter 12 Teilnahmen</option><option value="ready">12 Teilnahmen erreicht</option><option value="passed">Prüfung bestanden</option><option value="archived">Archiviert</option></select></label></div><div class="table-wrap"><table id="participants"><thead><tr><th>Name</th><th>Feuerwehr</th><th>Dieses Jahr</th><th>Gesamt</th><th>Stand</th></tr></thead><tbody>';
    foreach($people as $p){$st=$p['archived']?'archived':(($p['exam_date']||$p['exam_label'])?'passed':($p['total']>=12?'ready':'pending'));echo '<tr data-department="'.h($p['department']).'" data-status="'.$st.'"><td><a href="/?page=person&id='.$p['id'].'&year='.$year.'">'.h($p['last_name'].', '.$p['first_name']).'</a>'.($p['archived']?' <small>Archiviert</small>':'').'</td><td>'.h($p['department']).'</td><td>'.(int)$p['annual'].'</td><td><strong>'.(int)$p['total'].'</strong> / 12</td><td>'.status($p).'</td></tr>';}
    echo '</tbody></table></div><p id="filter-count" class="muted" aria-live="polite"></p></section><details class="card"><summary>Neues Ausbildungsjahr anlegen</summary><p>Übernimmt aktive Teilnehmer ohne bestandene Prüfung. Historische Teilnahmen zählen weiter, ohne sie nochmals als Vortrag anzulegen.</p><form method="post" class="filters">'.csrf().'<input type="hidden" name="action" value="year"><input type="hidden" name="source_year" value="'.$year.'">'.input('Neues Jahr','year',$year+1,'number','min="2010" max="2100" required').'<button>Jahr anlegen</button></form></details>';
}
if($page==='person') {
    $p=$id?$s->one('SELECT p.*,p.version person_version,e.*,e.version enrollment_version,p.id FROM participants p JOIN enrollments e ON e.participant_id=p.id WHERE p.id=? AND e.year=?',[$id,$year]):null;
    if($id&&!$p){http_response_code(404);echo '<p>Teilnehmer nicht gefunden.</p>';foot();exit;}
    $p??=['first_name'=>'','last_name'=>'','department'=>'','start_date'=>'','start_label'=>'','course'=>'','exam_date'=>'','exam_label'=>'','reported'=>'','comment'=>'','adjustment'=>0,'adjustment_reason'=>'','archived'=>0];
    echo '<a href="/?year='.$year.'">← Zur Übersicht</a>';
    if($id){$p['total']=$s->total($id,$year);$annual=$s->annual($id,$year);echo '<section class="card"><h2>'.h($p['first_name'].' '.$p['last_name']).'</h2><div class="person-total"><strong>'.$p['total'].' / 12</strong>'.status($p).'</div><p>'.$annual.' Teilnahmen im Jahr '.$year.' · '.($p['total']-$annual).' aus Vorjahren und Korrekturen</p><p>';foreach($s->rows('SELECT year FROM enrollments WHERE participant_id=? ORDER BY year',[$id]) as $e)echo '<a class="year-link" href="/?page=person&id='.$id.'&year='.$e['year'].'">'.$e['year'].'</a>';echo '</p></section>';}
    echo '<section class="card"><h2>'.($id?'Daten bearbeiten · '.$year:'Teilnehmer hinzufügen · '.$year).'</h2><form method="post">'.csrf().'<input type="hidden" name="action" value="person"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="year" value="'.$year.'"><input type="hidden" name="person_version" value="'.h($p['person_version']??1).'"><input type="hidden" name="enrollment_version" value="'.h($p['enrollment_version']??1).'"><div class="form-grid">'.input('Vorname','first_name',$p['first_name'],'text','required maxlength="100"').input('Nachname','last_name',$p['last_name'],'text','required maxlength="100"').input('Feuerwehr','department',$p['department'],'text','required maxlength="120"').input('Ausbildungsbeginn','start_date',$p['start_date'],'date').input('GA-Kreisnummer / Lehrgang','course',$p['course']).input('Prüfung bestanden am','exam_date',$p['exam_date'],'date').input('WL gemeldet / Arigon','reported',$p['reported']).'</div>';
    if($p['start_label'])echo '<p class="muted">Ursprünglicher Ausbildungsbeginn aus Excel: '.h($p['start_label']).'</p>';
    if($p['exam_label']&&!$p['exam_date'])echo '<p class="notice">Prüfungsvermerk aus Excel: '.h($p['exam_label']).' — bitte Datum ergänzen.</p>';
    echo '<label>Bemerkung<textarea name="comment" maxlength="3000">'.h($p['comment']).'</textarea></label><details><summary>Vortrag / Korrektur für '.$year.'</summary><p>Dieser Wert ist eine zusätzliche Korrektur, nicht die Gesamtzahl. Änderungen wirken auch auf spätere Jahre. Importierte Vorjahresstände wurden bereits abgeglichen.</p><div class="form-grid">'.input('Zusätzliche Teilnahmen (+/−)','adjustment',$p['adjustment'],'number','min="-10000" max="10000" required').input('Begründung','adjustment_reason',$p['adjustment_reason'],'text','maxlength="500"').'</div></details><label class="check"><input type="checkbox" name="archived" '.($p['archived']?'checked':'').'> Person archivieren (Historie erhalten)</label><button>Speichern</button></form></section>';
    if($id){
        echo '<section class="card"><h2>Wann war ich da?</h2><div class="table-wrap"><table><thead><tr><th>Datum / Jahr</th><th>Einheit</th><th>Ausbildungsinhalt</th></tr></thead><tbody>';
        $history=$s->rows('SELECT l.* FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=? ORDER BY l.year DESC,l.date DESC,l.unit DESC',[$id]);
        foreach($history as $l)echo '<tr><td>'.h($l['date']?date('d.m.Y',strtotime($l['date'])):$l['year'].' · Datum nicht hinterlegt').'</td><td>'.(int)$l['unit'].'</td><td><a href="/?page=lesson&id='.$l['id'].'">'.h($l['title']).'</a></td></tr>';
        if(!$history)echo '<tr><td colspan="3">Keine Einzelteilnahmen erfasst. Eventuelle Altbestände stehen unter Vortrag / Korrektur.</td></tr>';
        echo '</tbody></table></div></section><details class="card"><summary>Herkunft der Vorträge und Korrekturen</summary><ul>';
        foreach($s->rows('SELECT * FROM enrollments WHERE participant_id=? AND adjustment<>0 ORDER BY year',[$id])as $e)echo '<li>'.$e['year'].': '.sprintf('%+d',$e['adjustment']).' · '.h($e['adjustment_reason']).'</li>';
        echo '</ul></details>';
    }
}
if($page==='lessons') {
    echo '<section class="card"><div class="toolbar"><h2>Termine '.$year.'</h2><button type="button" class="secondary" id="print">Drucken</button></div><div class="table-wrap"><table><thead><tr><th>Datum</th><th>Einheit</th><th>Inhalt</th><th>Anwesend</th></tr></thead><tbody>';
    foreach($s->rows('SELECT l.*,(SELECT COUNT(*) FROM attendance a WHERE a.lesson_id=l.id) AS count FROM lessons l WHERE year=? ORDER BY date,unit,id',[$year])as $l)echo '<tr><td>'.h($l['date']?date('d.m.Y',strtotime($l['date'])):'Datum offen').'</td><td>'.$l['unit'].'</td><td><a href="/?page=lesson&id='.$l['id'].'">'.h($l['title']).'</a></td><td>'.$l['count'].'</td></tr>';
    echo '</tbody></table></div></section><section class="card"><h2>Termin hinzufügen</h2><form method="post">'.csrf().'<input type="hidden" name="action" value="lesson"><input type="hidden" name="id" value="0"><input type="hidden" name="year" value="'.$year.'"><div class="form-grid">'.input('Datum','date','','date','required').input('Einheit am selben Tag','unit',1,'number','min="1" max="20" required').input('Ausbildungsinhalt','title','','text','required maxlength="200"').'</div><button>Termin anlegen</button></form></section>';
}
if($page==='lesson') {
    $l=$s->one('SELECT * FROM lessons WHERE id=?',[$id]);if(!$l){http_response_code(404);echo '<p>Termin nicht gefunden.</p>';foot();exit;}
    echo '<a href="/?page=lessons&year='.$l['year'].'">← Zu den Terminen</a><section class="card"><h2>'.h($l['title']).'</h2><p>'.h($l['date']?date('d.m.Y',strtotime($l['date'])):$l['year'].' · Datum offen').' · Einheit '.$l['unit'].'</p><details><summary>Termin bearbeiten</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="lesson"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="year" value="'.$l['year'].'"><input type="hidden" name="version" value="'.$l['version'].'"><div class="form-grid">'.input('Datum','date',$l['date'],'date').input('Einheit','unit',$l['unit'],'number','required min="1" max="20"').input('Ausbildungsinhalt','title',$l['title'],'text','required maxlength="200"').'</div><button>Termin speichern</button></form></details></section>';
    $present=array_map('intval',array_column($s->rows('SELECT participant_id FROM attendance WHERE lesson_id=?',[$id]),'participant_id'));
    echo '<section class="card"><div class="toolbar"><h2>Anwesenheit</h2><button type="button" class="secondary" id="print">Liste drucken</button></div><p>'.count($present).' Teilnahmen erfasst. Bei getrennten Einheiten am selben Tag jede Einheit separat pflegen.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="attendance"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="version" value="'.$l['version'].'"><div class="attendance-list">';
    foreach($s->participants((int)$l['year'])as $p)echo '<label class="attendance-person"><input type="checkbox" name="present[]" value="'.$p['id'].'" '.(in_array((int)$p['id'],$present,true)?'checked':'').'><span><strong>'.h($p['last_name'].', '.$p['first_name']).'</strong><small>'.h($p['department']).($p['archived']?' · Archiviert':'').'</small></span><span class="signature">Unterschrift: ____________________</span></label>';
    echo '</div><div class="save-bar"><button>Anwesenheiten speichern</button></div></form></section>';
}
if($page==='import') {
    $imp=$s->one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
    echo '<section class="card"><h2>Excel-Übernahme</h2><p>Es wurden nur Teilnehmer und ihre x-Markierungen übernommen. Summenzeilen, veraltete Excel-Ergebnisse und leere Vorlagenzeilen zählen nicht als Teilnahme.</p><p>Die Gesamtzahl entsteht aus Einzelteilnahmen plus nachvollziehbaren Vorträgen/Korrekturen. Jahresüberträge werden dadurch nicht doppelt gezählt. Fehlende Inhalte und Termine wurden nicht erfunden.</p>';
    if($imp){$sum=json_decode($imp['summary'],true);echo '<p>'.$sum['people'].' Personen · '.$sum['lessons'].' Einheiten · '.$sum['attendance'].' Einzelteilnahmen</p><div class="table-wrap"><table><thead><tr><th>Jahr</th><th>Personen in Liste</th><th>Einzelteilnahmen</th></tr></thead><tbody>';foreach($sum['years']as $r)echo '<tr><td>'.$r['year'].'</td><td>'.$r['people'].'</td><td>'.$r['attendance'].'</td></tr>';echo '</tbody></table></div><p class="muted">Alle importierten Jahresstände wurden gegen die Einzelmarkierungen und Vorträge abgeglichen.</p>';}
    echo '</section><section class="card"><h2>Vorjahresabweichungen zur Prüfung</h2><p>Ein abweichender Excel-Vortrag wurde beibehalten und ausdrücklich als Korrektur gespeichert. Bitte diese Unterschiede fachlich prüfen.</p><div class="table-wrap"><table><thead><tr><th>Person</th><th>Jahr</th><th>Korrektur</th><th>Excel-Vortrag</th></tr></thead><tbody>';
    foreach($s->rows('SELECT e.*,p.first_name,p.last_name FROM enrollments e JOIN participants p ON p.id=e.participant_id WHERE e.adjustment<>0 AND EXISTS(SELECT 1 FROM enrollments z WHERE z.participant_id=e.participant_id AND z.year<e.year) ORDER BY e.year,p.last_name')as $e)echo '<tr><td><a href="/?page=person&id='.$e['participant_id'].'&year='.$e['year'].'">'.h($e['last_name'].', '.$e['first_name']).'</a></td><td>'.$e['year'].'</td><td>'.sprintf('%+d',$e['adjustment']).'</td><td>'.h($e['source_carry']).'</td></tr>';
    echo '</tbody></table></div></section><details class="card"><summary>Letzte Verwaltungsänderungen</summary><ul>';foreach($s->rows('SELECT at,event FROM audit ORDER BY id DESC LIMIT 30')as $a)echo '<li>'.h($a['at'].' · '.$a['event']).'</li>';echo '</ul></details>';
}
if($page==='account')echo '<section class="card auth"><h2>Passwort ändern</h2><p>'.h($account['email']).'</p><p>Der Zugang ist von Einsatzleiter.app unabhängig. Ein Passwortwechsel gilt ausschließlich hier.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="password">'.input('Aktuelles Passwort','current_password','','password','required autocomplete="current-password"').input('Neues Passwort (mindestens 12 Zeichen)','password','','password','required minlength="12" autocomplete="new-password"').input('Neues Passwort wiederholen','password_confirmation','','password','required autocomplete="new-password"').'<button>Passwort ändern und abmelden</button></form></section>';
foot();
