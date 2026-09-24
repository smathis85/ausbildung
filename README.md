# Ausbildungsverwaltung – eigenständiges DEV-Projekt

Eigenständige PHP-8.3-Anwendung mit SQLite, ohne Laravel-/Einsatzleiter-Abhängigkeit, ohne Zugriff auf Einsatzleiter-Datenbanken. Öffentlich erreichbar ist nur public/. Eigener Betriebssystembenutzer, PHP-FPM-Pool, Datenbank und Sitzungen. Keine externen Frontend-Abhängigkeiten oder Telemetrie.

## Umfang

- Ein fest erlaubter Admin: sascha.mathis@ffvgs.de. Keine öffentliche Registrierung, keine weiteren Feuerwehrzugänge in Phase 1.
- Einrichtung mit privatem, zeitlich begrenztem Einmalcode, anschließend eigenes Passwort. Passwortwechsel und Sitzungsablauf; CSRF, HTTPS, Anmelderatenbegrenzung, parametrisierte SQL-Abfragen, sichere Cookies und Ausgabe-Escaping.
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
