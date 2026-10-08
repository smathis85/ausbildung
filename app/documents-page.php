<?php
declare(strict_types=1);
// Administrator-only view, rendered through the authenticated main router.
$max=Ausbildung\Documents::size(Ausbildung\Documents::MAX_BYTES);
echo '<section class="card"><h2>Unterlage hochladen</h2><p>Freigegebene Unterlagen sehen alle angemeldeten Teilnehmer unter „Meine Teilnahmen“ und können sie ansehen oder herunterladen.</p><form method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="action" value="document_upload">';
echo '<label>Datei (höchstens '.h($max).')<input type="file" name="document" required data-max-bytes="'.Ausbildung\Documents::MAX_BYTES.'" accept="'.h(implode(',',array_map(fn($e)=>'.'.$e,array_keys(Ausbildung\Documents::TYPES)))).'"></label>';
echo '<p class="muted">Erlaubt: PDF, Word, Excel, PowerPoint, OpenDocument, Bilder, MP4/MP3, Text, CSV und ZIP.</p>';
echo '<div class="form-grid">'.input('Titel (leer lassen für den Dateinamen)','title','','text','maxlength="200"').input('Kurzbeschreibung (optional)','description','','text','maxlength="1000"').'</div>';
echo '<label class="check"><input type="checkbox" name="visible" checked> Für Teilnehmer freigeben</label><button>Hochladen</button></form></section>';
$docs=$documents->all(false);
echo '<section class="card"><h2>Vorhandene Unterlagen</h2>';
if(!$docs)echo '<p>Noch keine Unterlagen hochgeladen.</p>';
else{
    echo '<div class="table-wrap"><table><thead><tr><th>Titel</th><th>Datei</th><th>Hochgeladen</th><th>Teilnehmer</th><th>Aktionen</th></tr></thead><tbody>';
    foreach($docs as $d){
        $open='/?page=document&id='.(int)$d['id'];
        echo '<tr><td><a href="'.$open.'"'.(Ausbildung\Documents::viewable($d)?' target="_blank" rel="noopener"':'').'>'.h($d['title']).'</a>'.($d['description']!==''?'<small class="doc-description">'.h($d['description']).'</small>':'').'</td>';
        echo '<td>'.h($d['original_name']).'<small class="doc-description">'.h(strtoupper($d['extension']).' · '.Ausbildung\Documents::size((int)$d['size'])).'</small></td>';
        echo '<td>'.h(date('d.m.Y H:i',strtotime($d['created_at'].' UTC'))).($d['uploader']?'<small class="doc-description">'.h($d['uploader']).'</small>':'').'</td>';
        echo '<td>'.((int)$d['visible']?'<span class="badge ready">Freigegeben</span>':'<span class="badge">Nur Admins</span>').'</td>';
        echo '<td><details><summary>Bearbeiten</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="document_update"><input type="hidden" name="id" value="'.(int)$d['id'].'"><input type="hidden" name="version" value="'.(int)$d['version'].'">'.input('Titel','title',$d['title'],'text','required maxlength="200"').input('Kurzbeschreibung','description',$d['description'],'text','maxlength="1000"').'<label class="check"><input type="checkbox" name="visible" '.((int)$d['visible']?'checked':'').'> Für Teilnehmer freigeben</label><button>Speichern</button></form>';
        echo '<form method="post" data-confirm="Unterlage „'.h($d['title']).'“ endgültig löschen?">'.csrf().'<input type="hidden" name="action" value="document_delete"><input type="hidden" name="id" value="'.(int)$d['id'].'"><button class="danger">Löschen</button></form></details>';
        echo '<a href="'.$open.'&download=1">Herunterladen</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
