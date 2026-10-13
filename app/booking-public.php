<?php
declare(strict_types=1);
// Public Terminbuchung pages, reachable without login: list, booking form, result and management link.
use Ausbildung\Bookings;
if(in_array($page,['termine','termin','gebucht','buchung'],true)){
    $enabled=$appSettings->on('booking_enabled');
    $back='<p class="no-print"><a href="/?page=termine">← Alle Termine</a></p>';
    if($page==='buchung'){
        $token=is_string($_GET['t']??null)?$_GET['t']:'';
        $b=$bookings->byToken($token);
        head('Deine Buchung',false);
        if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
        if(!$b){http_response_code(404);echo '<section class="card auth"><p>Dieser Link ist ungültig oder abgelaufen. Nach einer Umbuchung gilt nur noch der Link aus der neuesten E-Mail.</p><a href="/?page=termine">Zu den Terminen</a></section>';foot();exit;}
        $state=['pending'=>'<span class="badge">Noch nicht bestätigt</span>','confirmed'=>'<span class="badge passed">Gebucht</span>','cancelled'=>'<span class="badge cancelled">Storniert</span>'][$b['status']];
        if((int)$b['event_cancelled'])$state='<span class="badge cancelled">Termin abgesagt</span>';
        echo '<section class="card"><div class="toolbar"><h2>'.h($b['title']).'</h2>'.$state.'</div><p><strong>'.h(Bookings::when($b)).'</strong>'.($b['location']!==''?'<br>'.h($b['location']):'').'</p>'
            .($b['description']!==''?'<p class="pre">'.h($b['description']).'</p>':'').'<dl class="facts"><dt>Name</dt><dd>'.h($b['name']).'</dd><dt>Feuerwehr</dt><dd>'.h($b['department']).'</dd><dt>E-Mail</dt><dd>'.h($b['email']).'</dd></dl></section>';
        $hidden=csrf().'<input type="hidden" name="t" value="'.h($token).'">';
        if($b['status']==='pending')echo '<section class="card"><h2>Buchung bestätigen</h2><p>Bitte bestätige deine Buchung, damit der Platz für dich reserviert bleibt.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="booking_confirm"><button>Platz verbindlich buchen</button></form></section>';
        if($bookings->changeable($b)){
            echo '<section class="card"><h2>Umbuchen</h2>';
            $targets=$bookings->rebookTargets($b);
            if(!$targets)echo '<p>Derzeit gibt es keinen anderen Termin mit freien Plätzen.</p>';
            else{
                echo '<form method="post">'.$hidden.'<input type="hidden" name="action" value="booking_rebook"><label>Neuer Termin<select name="target" required>';
                foreach($targets as $e)echo '<option value="'.(int)$e['id'].'">'.h($e['title'].' · '.Bookings::when($e).' · noch '.$bookings->free($e).' frei').($e['code_hash']?' · eigener Code':'').'</option>';
                echo '</select></label>';
                if(array_filter($targets,fn($e)=>$e['code_hash']))echo input('Buchungscode (nur für Termine mit eigenem Code)','booking_code','','password','maxlength="128" autocomplete="off"');
                echo '<button class="secondary">Umbuchen</button></form>';
            }
            echo '</section><section class="card"><h2>Stornieren</h2><p>Möglich bis '.h(date('d.m.Y, H:i',$bookings->changeDeadline($b))).' Uhr. Dein Platz wird dann für andere frei.</p><form method="post" data-confirm="Buchung wirklich stornieren?">'.$hidden.'<input type="hidden" name="action" value="booking_cancel"><button class="danger">Buchung stornieren</button></form></section>';
        }elseif($b['status']!=='cancelled'&&!(int)$b['event_cancelled'])echo '<p>Änderungen über diesen Link sind nicht mehr möglich. Bitte wende dich bei Fragen an die Ausbildungsleitung.</p>';
        echo $back;foot();exit;
    }
    if($page==='gebucht'){
        $done=$_SESSION['booking_done']??null;
        if(!$done)go('/?page=termine');
        head($done['pending']?'Fast geschafft':'Buchung bestätigt',false);
        echo '<section class="card auth"><h2>'.h($done['title']).'</h2><p>'.h($done['when']).'</p>';
        if($done['pending'])echo '<p>Wir haben dir eine E-Mail an <strong>'.h($done['email']).'</strong> geschickt. Bitte bestätige die Buchung über den Link darin, sonst verfällt der Platz.</p>';
        else echo '<p>Dein Platz ist gebucht. Eine Bestätigung mit Kalendereintrag geht an <strong>'.h($done['email']).'</strong>. Darin findest du auch den Link zum Stornieren oder Umbuchen.</p><p><a href="/?page=buchung&amp;t='.h($done['token']).'">Buchung jetzt ansehen</a></p>';
        echo '<p class="muted">Keine E-Mail erhalten? Bitte auch im Spam-Ordner nachsehen.</p><a class="button secondary" href="/?page=termine">Weitere Termine</a></section>';foot();exit;
    }
    head('Termine buchen',false);
    if($error)echo '<p class="error" role="alert">'.h($error).'</p>';
    if(!$enabled){echo '<section class="card auth"><p>Die Terminbuchung ist derzeit nicht freigeschaltet.</p></section>';foot();exit;}
    if(booking_list_locked()){
        echo '<section class="card auth"><h2>Buchungscode</h2><p>Bitte gib den Buchungscode ein, den du von deiner Feuerwehr oder der Ausbildungsleitung erhalten hast.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="booking_unlock">'.input('Buchungscode','booking_code','','password','required maxlength="128" autocomplete="off"').'<button>Weiter</button></form></section>';
        foot();exit;
    }
    if($page==='termin'){
        $event=$bookings->event(filter_var($_GET['id']??0,FILTER_VALIDATE_INT)?:0);
        if(!$event||!(int)$event['visible']||(int)$event['cancelled']||Bookings::at($event['starts_at'])<=time()){http_response_code(404);echo '<section class="card auth"><p>Diesen Termin gibt es nicht (mehr).</p></section>'.$back;foot();exit;}
        $free=$bookings->free($event);
        echo $back.'<section class="card"><h2>'.h($event['title']).'</h2><p><strong>'.h(Bookings::when($event)).'</strong>'.($event['location']!==''?'<br>'.h($event['location']):'').'</p>'
            .($event['description']!==''?'<p class="pre">'.h($event['description']).'</p>':'').'<p>'.($free?'<span class="badge ready">Noch '.$free.' von '.(int)$event['capacity'].' Plätzen frei</span>':'<span class="badge cancelled">Ausgebucht</span>').'</p></section>';
        if(!$bookings->bookingOpen($event))echo '<p class="notice">Die Buchung für diesen Termin ist geschlossen.</p>';
        elseif($free){
            $_SESSION['booking_form'][(int)$event['id']]??=time();
            $v=fn(string $k)=>$_SERVER['REQUEST_METHOD']==='POST'&&is_string($_POST[$k]??null)?$_POST[$k]:'';
            echo '<section class="card auth"><h2>Platz buchen</h2><form method="post" class="booking-form">'.csrf().'<input type="hidden" name="action" value="book"><input type="hidden" name="event_id" value="'.(int)$event['id'].'">'
                .input('Vor- und Nachname','name',$v('name'),'text','required maxlength="120" autocomplete="name"');
            if($appSettings->get('booking_department_mode')==='free')echo input('Feuerwehr','department_other',$v('department_other'),'text','required maxlength="120"');
            else{
                echo '<label>Feuerwehr<select name="department" required><option value="">Bitte auswählen</option>';
                foreach($bookings->departments()as$d)echo '<option value="'.h($d).'" '.($v('department')===$d?'selected':'').'>'.h($d).'</option>';
                echo '<option value="__other" '.($v('department')==='__other'?'selected':'').'>Andere Feuerwehr …</option></select></label>'.input('Andere Feuerwehr (nur wenn nicht in der Liste)','department_other',$v('department_other'),'text','maxlength="120" class="department-other"');
            }
            echo input('E-Mail-Adresse','email',$v('email'),'email','required maxlength="254" autocomplete="email"');
            if(booking_code_needed($event))echo input($event['code_hash']?'Buchungscode für diesen Termin':'Buchungscode','booking_code','','password','required maxlength="128" autocomplete="off"');
            echo '<label class="hp" aria-hidden="true">Website<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>'
                .'<label class="check"><input type="checkbox" name="privacy" required> '.h($appSettings->get('booking_privacy_text')).'</label><button>Verbindlich buchen</button></form></section>';
        }
        foot();exit;
    }
    $events=$bookings->publicEvents();
    echo '<p>Wähle einen Termin aus. Du brauchst kein Konto. Nach der Buchung erhältst du eine Bestätigung per E-Mail mit einem Link zum Stornieren oder Umbuchen.</p>';
    if(!$events)echo '<section class="card"><p>Derzeit sind keine Termine zur Buchung freigegeben.</p></section>';
    else{
        echo '<ul class="booking-list">';
        foreach($events as $e){
            $free=$bookings->free($e);$open=$bookings->bookingOpen($e);
            echo '<li class="card"><div><h2><a href="/?page=termin&amp;id='.(int)$e['id'].'">'.h($e['title']).'</a></h2><p>'.h(Bookings::when($e)).($e['location']!==''?' · '.h($e['location']):'').'</p></div><div>'
                .(!$open?'<span class="badge">Buchung geschlossen</span>':($free?'<span class="badge ready">Noch '.$free.' frei</span>':'<span class="badge cancelled">Ausgebucht</span>'))
                .($open&&$free?' <a class="button" href="/?page=termin&amp;id='.(int)$e['id'].'">Buchen</a>':'').'</div></li>';
        }
        echo '</ul>';
    }
    foot();exit;
}
