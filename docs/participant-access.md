# Teilnehmeranmeldung mit zentralem Code

Vom Benutzer ausdrücklich gewählt: Vorname, Nachname und ein gemeinsamer Ausbildungscode. Das ist eine gemeinsame Zugangshürde, keine persönliche Identitätsprüfung. Wer den Code kennt, kann einen fremden Namen eingeben. Dieser Umfang wurde erläutert und vom Benutzer akzeptiert. Umsetzung zunächst ausschließlich DEV; Produktion nicht freigegeben.

## Bedienung

Als Admin unter Zugang einen Code mit 8–128 Zeichen setzen und Teilnehmerzugang freischalten. Standardmäßig ausgeschaltet. Code wird nur als Hash gespeichert (SHA-256 vor password_hash vermeidet Bcrypt-Längenabschneidung) und nie wieder im Klartext angezeigt oder protokolliert. Code leer lassen erhält einen bestehenden Code. Änderungen und Sperrung erhöhen eine Versionsnummer und beenden bestehende Teilnehmeranmeldungen.

Öffentliche Startseite enthält getrennte Einstiege für Teilnehmer und Admin. Teilnehmer melden sich unter /?page=participant-login an und sehen unter /?page=me ausschließlich die zum aufgelösten Namen gehörenden Daten. Namen werden Unicode-normalisiert, ohne Beachtung von Groß-/Kleinschreibung und äußerem/mehrfachem Leerraum verglichen. Bei Namensgleichheit ist optional die Feuerwehr erforderlich. Bleibt die Zuordnung mehrdeutig, wird kein Datensatz gewählt. Archivierte Personen erhalten keinen Zugang.

## Trennung

Teilnehmersitzung enthält ausschließlich die serverseitig aufgelöste Teilnehmer-ID und Codeversion; URL-IDs beeinflussen die eigene Übersicht nicht. Keine Admin-Session-ID. Teilnehmer erhalten keinen Zugriff auf Kommentare, WL-/Arigon-Vermerke, Importdetails, Gesamtlisten, Exporte, andere Teilnehmer oder Schreibaktionen. Bekannte gemeinsame Codeeigenschaft bleibt ausdrücklich bestehen: Eine Neuanmeldung unter einem anderen Namen ist mit dem gemeinsamen Code möglich.

CSRF-Schutz, Sitzungswechsel bei Anmeldung, Secure/HttpOnly/SameSite-Cookies, generische Loginfehler, Begrenzung fehlgeschlagener Versuche, automatische Abmeldung nach einer Stunde Inaktivität bzw. acht Stunden Gesamtdauer. Sperrung/Codewechsel/Archivierung werden bei jeder Anfrage geprüft. Codeprüfung und Auflösung der Sitzungs-Codeversion erfolgen in einer Transaktion.

## Tests / Bereitstellung

Neue Tests: tests/participant_overview.php, tests/participant_access.php, tests/participant_http.py. Künstliche Daten: persönliche Datenbegrenzung, 12er-Grenze, Codehash, deaktivierter Standard, Namen/Umlaute, Namensgleichheit, Versionswechsel, Sperrung, ID-Manipulation, keine Verwaltungsansichten/-Exporte/-Schreibaktionen, CSRF und Ratenbegrenzung. Bestehende Store-, Web- und Produktionsübernahmetests bestanden.

DEV-Updatepaket /home/einsatzadmin/ausbildung-participants-dev-stage, enger Root-Installer update.sh: Datenbankbackup und Codebackup, additive idempotente Tabelle participant_access, atomare Dateiersetzung und PHP-Reload. Keine Excel-Neuübernahme, kein Zurücksetzen des Admin-Passworts, keine Produktionseingriffe. Administrative Ausführung benötigt, da /opt/ausbildung-dev root gehört und der SSH-Zugang keine entsprechende passwortlose sudo-Freigabe besitzt.

Produktionsfreigabe durch Benutzer nachgereicht: „kann zu main / prod“. Produktionsmodus mit participant_http.py und beiden persönlichen Zugriffstests erneut erfolgreich geprüft. Aktueller Produktionscode entspricht der erwarteten Main-Basis; keine Fremdänderungen festgestellt. Enges Produktionsupdate unter /home/einsatzadmin/ausbildung-participants-prod-stage/update.sh vorbereitet. Nur additive Tabelle, aktuelle Produktionsdaten beibehalten, kein DEV-Datenimport. Datenbank- und Codebackup vor Update. Administrativer Schritt aufgrund root-eigener Dateien weiterhin erforderlich.

Stand nach „bereit“: Produktionsinstallation am 24.09.2026 verifiziert. Alle fünf ausgelieferten PHP-Dateien entsprechen per SHA-256 dem getesteten Main-Stand. Startseite HTTP 200 mit Teilnehmer-Einstieg, ohne DEV-Kennzeichnung. Produktionscookie Secure/HttpOnly/SameSite=Strict, CSP und no-store vorhanden. Teilnehmerzugang noch nicht freigeschaltet; auch manipulierte me-ID zeigt keine Übersicht. Account, Export und Teilnehmerverwaltung verlangen Admin-Anmeldung. Private Datenbank, Quellcode und .git/config liefern 404. Ausbildungs-DEV und Einsatzleiter.app weiter HTTP 200; Nginx/PHP/Cron aktiv. Keinen zentralen Code oder private Daten ausgelesen. Admin muss unter Zugang den gemeinsamen Code setzen und Teilnehmerzugang freischalten.
