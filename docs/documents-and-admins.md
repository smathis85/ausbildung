# Unterlagen und weitere Administratoren (Oktober 2026)

Vom Benutzer gewünscht: Admins laden Dateien hoch, angemeldete Teilnehmer sehen und laden sie herunter; zusätzlich weitere Admins anlegen. Einsatzleiter.app bleibt unverändert. Umsetzung zuerst auf DEV, Produktion erst nach ausdrücklicher Freigabe.

## Unterlagen

- Menüpunkt „Unterlagen“ für Admins: Datei hochladen (bis 50 MB), Titel/Kurzbeschreibung, Freigabe für Teilnehmer, bearbeiten, löschen.
- Erlaubte Endungen: pdf, Office/OpenDocument, Bilder (png/jpg/gif/webp), mp4/mp3, txt, csv, rtf, zip. Kein HTML/SVG/PHP. Content-Type wird aus der Endung bestimmt, nicht vom Browser übernommen.
- Ablage außerhalb von public/: `/var/lib/<instanz>/documents/<zufälliger Name>` (0600). Metadaten in Tabelle `documents`.
- Teilnehmer sehen freigegebene Unterlagen unter „Meine Teilnahmen“. PDF, Bilder, Video, Audio und Text lassen sich direkt ansehen, alles andere wird heruntergeladen. Nicht freigegebene Unterlagen antworten Teilnehmern mit 404. Ohne Anmeldung wird keine Datei ausgeliefert.
- Auslieferung durch PHP mit eigener restriktiver CSP, nosniff und Range-Unterstützung (Video auf iOS).
- Sicherung: `bin/backup.php` sichert nur die Datenbank. Der Ordner `documents` muss in die externe Serversicherung.

## Administratoren

- Die Tabelle `accounts` erlaubte bisher nur id=1. `manage.php init` baut sie einmalig ohne diese Beschränkung neu auf; E-Mail, Passwort-Hash und Version bleiben erhalten.
- Hauptadmin (id 1) legt unter „Zugang“ weitere Admins mit Name und E-Mail an. Die App zeigt einmalig einen Einrichtungscode (7 Tage gültig, nur als Hash gespeichert). Die Person setzt auf `/?page=setup` mit E-Mail und Code ein eigenes Passwort.
- Nur der Hauptadmin kann Admins anlegen, einen neuen Code erzeugen (Passwort und Sitzungen werden ungültig) oder entfernen. Der Hauptadmin selbst kann nicht entfernt werden. Weitere Admins haben ansonsten dieselben Rechte.

## Bereitstellung

`python3 deploy/build_update.py ZIEL` erzeugt `update.tar` und `update.sh` (Prüfsumme fest eingebunden). Auf dem Server: `sudo bash ZIEL/update.sh dev` (bzw. `prod`). Das Skript sichert Datenbank, Code, PHP-Pool und vhost, ersetzt app/public/bin, führt die Migration aus, setzt Upload-Grenzen (PHP 50 MB, Nginx 52 MB) nur für diese Anwendung, prüft die Konfiguration vor dem Reload und nimmt sie bei Fehlern zurück.

Tests: `php tests/admin_accounts.php`, `python3 tests/documents_http.py` (synthetische Daten, temporäre Datenbank).
