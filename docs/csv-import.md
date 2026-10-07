# Teilnehmer-CSV-Import

Admin-only unter „CSV importieren“ auf der Übersicht sowie unter Importprüfung.
Vorlage: Vorname;Nachname;Feuerwehr;Ausbildungsbeginn;Lehrgang.
Pflichtspalten: Vorname, Nachname, Feuerwehr. UTF-8 mit optionalem BOM,
Semikolon oder Komma, maximal 512 KiB und 1.000 Teilnehmer. Datum ISO oder TT.MM.JJJJ.

Vorschau in privater serverseitiger Sitzung (20 Minuten), Bestätigung mit CSRF und
Einmaltoken. Originalupload wird nicht dauerhaft abgelegt. Doppelte Identitäten
(normalisierter Vorname, Nachname, Feuerwehr) einschließlich archivierter Personen
werden übersprungen. Vorhandene Personen werden nicht verändert oder neu eingeschrieben.
Neue Personen erhalten eine Einschreibung im ausgewählten Jahr ohne Teilnahmen,
Prüfung oder Vortrag. Die Vorschau allein schreibt keine Daten.

Bestätigung prüft Dubletten erneut innerhalb einer SQLite-Schreibtransaktion.
Fehler führen zum vollständigen Rollback. Audit enthält Jahr und Anzahlen, keine CSV-Inhalte.
Keine Migration erforderlich. Nach ausdrücklicher Freigabe auf DEV und Produktion bereitgestellt. Produktionsdateien mit DEV verglichen, Datenbank vorher gesichert und Anmeldeschutz der CSV-Routen geprüft. Es wurden keine Teilnehmer beim Deployment importiert.

Tests: participant_csv.php, csv_http.py, participant_http.py, web_smoke.py.

## Upload-Verzeichnis (07.10.2026)

PHP speicherte Uploads in `/tmp/ausbildung` bzw. `/tmp/ausbildung-dev`. Ubuntu leert `/tmp` beim Neustart,
danach scheiterte jeder CSV-Upload mit „Bitte eine CSV-Datei auswählen“. Upload- und Temp-Verzeichnis liegen
jetzt dauerhaft unter `/var/lib/<instanz>/tmp` (FPM-Pools, Installer). Bestehende Server einmalig mit
`sudo bash deploy/fix-upload-tmp.sh prod` bzw. `dev` umstellen. Serverseitige Upload-Fehler zeigen jetzt eine
eigene Meldung und werden im PHP-Fehlerlog protokolliert.
