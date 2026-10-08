<?php
declare(strict_types=1);
// Administrator-only section of the account page. Only the primary administrator may change other admins.
echo '<section class="card" id="admins"><h2>Administratoren</h2><p>Administratoren haben vollen Zugriff auf die Ausbildungsverwaltung und die Unterlagen. Jeder Admin hat einen eigenen Zugang mit eigenem Passwort.</p>';
$newCode=$_SESSION['admin_code']??null;unset($_SESSION['admin_code']);
if($newCode&&$isPrimaryAdmin){
    echo '<div class="notice" role="status"><p><strong>Einmaliger Einrichtungscode für '.h($newCode['email']).':</strong></p><p class="setup-code">'.h($newCode['code']).'</p><p>Der Code wird nur jetzt angezeigt und ist '.Ausbildung\AdminAccounts::SETUP_DAYS.' Tage gültig. Gib ihn der Person persönlich oder auf einem sicheren Weg weiter. Sie öffnet <strong>'.h(($_SERVER['HTTP_HOST']??'').'/?page=setup').'</strong> und legt dort mit E-Mail-Adresse und Code ihr eigenes Passwort fest.</p></div>';
}
echo '<div class="table-wrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Status</th>'.($isPrimaryAdmin?'<th>Aktionen</th>':'').'</tr></thead><tbody>';
foreach($admins->all() as $a){
    $primary=(int)$a['id']===Ausbildung\AdminAccounts::PRIMARY_ID;
    $state=(int)$a['active']?'<span class="badge ready">Aktiv</span>':((int)$a['setup_expires']>=time()?'<span class="badge">Einrichtung offen bis '.h(date('d.m.Y',(int)$a['setup_expires'])).'</span>':'<span class="badge">Einrichtungscode abgelaufen</span>');
    echo '<tr><td>'.h($a['name']!==''?$a['name']:($primary?'Hauptadministrator':'–')).((int)$a['id']===(int)$account['id']?' <small>(du)</small>':'').'</td><td>'.h($a['email']).'</td><td>'.$state.($primary?' <span class="badge passed">Hauptadmin</span>':'').'</td>';
    if($isPrimaryAdmin){
        echo '<td>';
        if(!$primary)echo '<div class="actions"><form method="post" data-confirm="Neuen Einrichtungscode für '.h($a['email']).' erzeugen? Das bisherige Passwort wird ungültig.">'.csrf().'<input type="hidden" name="action" value="admin_reset"><input type="hidden" name="id" value="'.(int)$a['id'].'"><button class="secondary">Neuer Code</button></form><form method="post" data-confirm="'.h($a['email']).' als Administrator entfernen?">'.csrf().'<input type="hidden" name="action" value="admin_remove"><input type="hidden" name="id" value="'.(int)$a['id'].'"><button class="danger">Entfernen</button></form></div>';
        echo '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';
if($isPrimaryAdmin)echo '<h3>Weiteren Admin anlegen</h3><form method="post">'.csrf().'<input type="hidden" name="action" value="admin_create"><div class="form-grid">'.input('Name','name','','text','required maxlength="100" autocomplete="off"').input('E-Mail-Adresse','email','','email','required maxlength="254" autocomplete="off"').'</div><button>Admin anlegen und Einrichtungscode erzeugen</button></form>';
else echo '<p class="muted">Weitere Administratoren legt der Hauptadministrator an.</p>';
echo '</section>';
