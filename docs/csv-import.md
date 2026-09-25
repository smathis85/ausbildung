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
