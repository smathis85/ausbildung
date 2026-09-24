<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$dir=getenv('TRAINING_DATA_DIR')?:__DIR__.'/../private';
if(!is_file($dir.'/training.sqlite'))throw new RuntimeException('Datenbank fehlt.');
if(!is_dir($dir.'/backups'))mkdir($dir.'/backups',0700,true);
$lock=fopen($dir.'/backup.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit(0);
$path=$dir.'/backups/training-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
$source=new SQLite3($dir.'/training.sqlite',SQLITE3_OPEN_READONLY);$source->busyTimeout(5000);
$target=new SQLite3($path);$target->busyTimeout(5000);
if(!$source->backup($target)||$target->querySingle('PRAGMA integrity_check')!=='ok'){unlink($path);throw new RuntimeException('Sicherung fehlgeschlagen.');}
$source->close();$target->close();chmod($path,0600);
$files=glob($dir.'/backups/training-*.sqlite');rsort($files,SORT_STRING);
foreach(array_slice($files,14)as$file)if(preg_match('/\/training-\d{8}-\d{6}-[a-f0-9]{6}\.sqlite$/',$file))unlink($file);
echo "Ausbildungsdatenbank gesichert und geprüft.\n";
