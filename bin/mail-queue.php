<?php
declare(strict_types=1);
// Cron (every 5 minutes): send queued booking mails, remove expired and old booking data. Never prints personal data.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../app/Store.php';
require __DIR__.'/../app/Settings.php';
require __DIR__.'/../app/Mailer.php';
require __DIR__.'/../app/Bookings.php';
date_default_timezone_set('Europe/Berlin');
umask(0077);
$dir=getenv('TRAINING_DATA_DIR')?:__DIR__.'/../private';
if(!is_file($dir.'/training.sqlite'))exit(0);
$lock=fopen($dir.'/mail-queue.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit(0);
$store=new Ausbildung\Store($dir.'/training.sqlite');
if(!$store->one("SELECT name FROM sqlite_master WHERE type='table' AND name='mail_outbox'"))exit(0);
$settings=new Ausbildung\Settings($store);
$mailer=new Ausbildung\Mailer($store,$settings,$dir.'/mail.key');
$result=$mailer->flush(50);
$purged=(new Ausbildung\Bookings($store,$settings))->purge();
$mailer->purge();
if($result['sent']||$result['failed']||$purged)echo date('c').' gesendet '.$result['sent'].', fehlgeschlagen '.$result['failed'].', Buchungen gelöscht '.$purged."\n";
