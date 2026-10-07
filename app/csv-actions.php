<?php
declare(strict_types=1);
// Included only after the administrator and CSRF checks.
if($action==='csv_preview'){
    unset($_SESSION['csv_import']);
    $year=number('year',2010,2100);
    $file=$_FILES['csv_file']??null;
    $uploadError=is_array($file)?(int)($file['error']??UPLOAD_ERR_NO_FILE):UPLOAD_ERR_NO_FILE;
    if(in_array($uploadError,[UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION],true)){
        error_log('CSV-Upload: Serverfehler beim Zwischenspeichern (PHP-Upload-Code '.$uploadError.')');
        throw new RuntimeException('Der Server konnte die Datei nicht zwischenspeichern. Bitte die Administration informieren.');
    }
    if($uploadError===UPLOAD_ERR_INI_SIZE||$uploadError===UPLOAD_ERR_FORM_SIZE)throw new RuntimeException('Die CSV-Datei darf höchstens 512 KB groß sein.');
    if($uploadError!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Bitte eine CSV-Datei auswählen (maximal 512 KB).');
    if(($file['size']??0)>524288)throw new RuntimeException('Die CSV-Datei darf höchstens 512 KB groß sein.');
    if(strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='csv')throw new RuntimeException('Bitte eine Datei mit der Endung .csv auswählen.');
    $rows=Ausbildung\ParticipantCsv::parse(file_get_contents($file['tmp_name']));
    $_SESSION['csv_import']=['rows'=>$rows,'year'=>$year,'expires'=>time()+1200,'token'=>bin2hex(random_bytes(24))];
    go('/?page=csv-import');
}
if($action==='csv_confirm'){
    $draft=$_SESSION['csv_import']??null;
    if(!$draft||$draft['expires']<time()||!hash_equals($draft['token'],field('import_token','',48))){unset($_SESSION['csv_import']);throw new RuntimeException('Die Vorschau ist abgelaufen oder wurde bereits importiert. Bitte die CSV-Datei erneut prüfen.');}
    $result=(new Ausbildung\ParticipantCsv($s))->apply($draft['rows'],$draft['year']);
    unset($_SESSION['csv_import']);
    $_SESSION['flash']=$result['added'].' Teilnehmer importiert, '.$result['skipped'].' bereits vorhandene oder doppelte Einträge übersprungen.';
    go('/?year='.$draft['year']);
}
if($action==='csv_cancel'){unset($_SESSION['csv_import']);go('/?page=csv-import');}
