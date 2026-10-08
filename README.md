# Ausbildungsverwaltung – eigenständiges DEV-Projekt

## Freigegebene Produktionsübernahme

Der Benutzer hat die Produktionsbereitstellung unter `ausbildung.einsatzleiter.app` freigegeben. `deploy/build_package.py ZIEL --production` erstellt ein separates, prüfsummengebundenes Paket ohne Excel- oder Benutzerdaten. Der Produktionsinstaller kopiert beim ersten Lauf mit der SQLite-Backup-API den aktuellen DEV-Datenstand nach `/var/lib/ausbildung/training.sqlite`, einschließlich des bestehenden Passwort-Hashes. Er gibt keine Zugangsdaten aus und kopiert keine Sitzungen. Ein noch nicht aktivierter Zugang erhält einen neuen privaten Einrichtungscode.

Eigene Produktionspfade: `/opt/ausbildung`, `/var/lib/ausbildung`, Systembenutzer und PHP-FPM-Pool `ausbildung`, eigener vhost und tägliche Sicherung um 03:25 Uhr. DEV bleibt eigenständig bestehen; Daten werden nach der einmaligen Übernahme nicht synchronisiert. Bestehende Produktionsdaten werden bei wiederholter Installation niemals erneut aus DEV importiert. Die Produktionsdarstellung wird ausschließlich über `TRAINING_ENV=production` aktiviert und verwendet einen eigenen Cookie-Namen.

Installation als Administrator: `sudo bash /home/einsatzadmin/ausbildung-prod-stage/install.sh`. Vor der Freischaltung prüft der Installer DNS, Zertifikat, SQLite-Integrität, Konfiguration und Dateizugriffsschutz. Externe Backups sind wie bei DEV separat zu berücksichtigen. Zur Stilllegung nur den Produktions-vhost aus sites-enabled nehmen; Daten und DEV unberührt lassen.

Eigenständige PHP-8.3-Anwendung mit SQLite, ohne Laravel-/Einsatzleiter-Abhängigkeit, ohne Zugriff auf Einsatzleiter-Datenbanken. Öffentlich erreichbar ist nur public/. Eigener Betriebssystembenutzer, PHP-FPM-Pool, Datenbank und Sitzungen. Keine externen Frontend-Abhängigkeiten oder Telemetrie.

## Umfang

- Hauptadmin sascha.mathis@ffvgs.de; weitere Admins legt der Hauptadmin unter „Zugang“ an (docs/documents-and-admins.md). Unterlagen-Upload für Teilnehmer siehe ebenda. Keine öffentliche Registrierung. Erweiterung auf dem DEV-Featurebranch: Teilnehmer-Leseansicht mit Name und zentralem Code, durch Admin explizit freizuschalten; siehe docs/participant-access.md.
- Einrichtung mit privatem, zeitlich begrenztem Einmalcode, anschließend eigenes Passwort. Passwortwechsel und Sitzungsablauf; CSRF, HTTPS, Anmelderatenbegrenzung, parametrisierte SQL-Abfragen, sichere Cookies und Ausgabe-Escaping.
- Terminplan je Jahr (Druck, Teilnehmeransicht, Grundlage für Termine), siehe docs/schedule.md.
- Jahrgangsübersicht mit Suche/Feuerwehr-/Statusfilter, Teilnehmerpflege, Archivierung, Termine, mehrere Einheiten je Tag, Anwesenheiten, Einzelhistorie, Druckliste, geschützter CSV-Export und Jahresübernahme.
- Prüfungsgrenze 12 Teilnahmen, unabhängig von der separat gepflegten bestandenen Prüfung. Keine zusätzliche zweijährige Mindestdauer erfunden. Keine Stunden-/Themenpflichtberechnung ohne entsprechende Daten.
- Importprüfung zeigt 3 abweichende Jahresüberträge. Fehlende Datums-/Inhaltsangaben bleiben sichtbar unvollständig.

## Berechnung

Gesamtstand pro Jahr = alle bis dahin erfassten Einzelteilnahmen + alle bis dahin erfassten Vorträge/Korrekturen. Einmaliger Anfangsvortrag je Person; danach nur Differenz zwischen Excel-Vortrag und vorherigem erfassten Gesamtstand. Dadurch bleibt die Excel-Zählung erhalten, ohne Vorjahre doppelt zu zählen. Korrekturen früherer Anwesenheiten wirken auf spätere Jahre. Importiert werden x-Markierungen, nicht die teils fehlerhaften oder veralteten Excel-Summen. Zwei Spalten desselben Tages bleiben zwei Einheiten.

Jahresübernahme übernimmt aktive Personen ohne bestandene Prüfung. Historische Personen werden nicht gelöscht. Personen werden beim Erstimport anhand getrimmtem Namen, Vornamen und Feuerwehr zusammengeführt. Ein Feuerwehrwechsel oder eine abweichende Namensschreibweise kann deshalb getrennte Personen ergeben; kein unsicherer automatischer Namensabgleich. Es gibt noch keine Zusammenführungsfunktion.

## Entwicklung / Tests

`php tests/store.php` und `python3 tests/web_smoke.py` verwenden ausschließlich temporäre Datenbanken und künstliche Daten. Der Webtest startet einen PHP-Testserver nur auf 127.0.0.1. `TRAINING_LOCAL_TEST=1` ist nur hierfür zulässig, niemals im FPM-Pool.

`bin/extract_excel.py QUELLE.xlsx private/source-import.json` liest mit openpyxl, ändert niemals die Quelldatei. Importdaten und Datenbanken stehen in .gitignore. Keine Passwörter oder Personendaten in Git.

## Installation

Vorgesehene Adresse: https://ausbildung-dev.einsatzleiter.app. A-Record 31.70.130.202; vorhandenes Wildcard-Zertifikat wird verwendet. Separater vhost und FPM-Pool. Vorhandene Einsatzleiter-Anwendungen und ihre Daten bleiben unverändert. Webserver/PHP werden nach Konfigurationsprüfung neu geladen.

Der geprüfte Installer wird als Root ausgeführt. Er prüft die festen Paketprüfsummen, sichert bei Wiederholung die vorhandene SQLite-Datenbank, importiert nur einmal und überschreibt keinen eingerichteten Zugang. Einrichtungscode liegt ausschließlich in `/var/lib/ausbildung-dev/setup-code.txt`, ist sieben Tage gültig und wird nach Einrichtung gelöscht. Auf `/?page=setup` E-Mail, Code und selbst gewähltes Passwort eingeben. Kein Code in einer URL, keinem Webserver-Log und keiner öffentlichen Datei.

Zugang zurücksetzen ausschließlich durch berechtigten Serveradministrator: `sudo -u ausbildung-dev env TRAINING_DATA_DIR=/var/lib/ausbildung-dev php /opt/ausbildung-dev/bin/manage.php reset-access`. Dadurch werden vorhandene Sitzungen ungültig, ein neuer Einmalcode ist 24 Stunden gültig.

## Sicherung und Rücknahme

Separate Sicherung von /var/lib/ausbildung-dev/training.sqlite (SQLite-Backup-API verwenden, nicht während Schreibzugriffen unkoordiniert kopieren). Die Ausbildungsdatenbank muss in die regelmäßige Serversicherung aufgenommen werden; der Installer richtet dafür eine tägliche SQLite-Sicherung mit 14 lokalen Ständen ein. Eine externe Sicherung bleibt Aufgabe des vorhandenen Server-Backups.

Zur Stilllegung nur den Link `/etc/nginx/sites-enabled/ausbildung-dev.einsatzleiter.app` entfernen, nginx prüfen und neu laden. Datenbank und Dateien zur Wiederherstellung behalten. Nie produktive Einsatzleiter-Konfigurationen oder Datenbanken zurücksetzen. Weitere Rollen, öffentliche Feuerwehrzugänge und eine Produktionsbereitstellung benötigen einen eigenen Auftrag.
