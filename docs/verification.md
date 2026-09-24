# DEV-Prüfstand – 24.09.2026

## Bestätigter Umfang

Vollständig getrennte Ausbildungsverwaltung. Ein Admin sascha.mathis@ffvgs.de; Passwort setzt er selbst über einen privaten Einmalcode. Prüfungsgrenze 12 Teilnahmen. Eigene Datenbank, eigener Betriebssystembenutzer/PHP-Pool, eigene Sitzungen. Keine gemeinsame Authentifizierung oder Einsatzleiter-Datenbank. Kein Produktionsauftrag.

## Datenübernahme

Quelle: Anwesenheitslisten2026 (1).xlsx, ausschließlich lesend verarbeitet.

- 2019–2026, 192 nach Name/Vorname/Feuerwehr zugeordnete Personen, 75 Einheiten, 1.435 Einzelteilnahmen.
- 579 Jahresstände gegen Vortrag plus x-Markierungen abgeglichen; alle stimmen.
- 2026: 84 Personen, 177 Einzelteilnahmen.
- 3 abweichende Folgejahresvorträge separat als nachvollziehbare Korrekturen erhalten.
- Datum/Unterrichtsinhalte teilweise nicht vorhanden; kein Erfinden von Inhalten, Stunden oder zusätzlichen Zulassungsbedingungen.
- Excel-Summen und Zeilen mit Summenformeln nicht als Teilnehmer importiert. Wiederholter identischer Import erzeugt keine Duplikate.

## Erfolgreiche Prüfungen

PHP-Syntaxprüfung aller PHP-Dateien. Store-Tests mit temporärer SQLite: Import, Jahresberechnung, 11/12-Grenze, getrennte Einheiten am selben Datum, wiederholtes Speichern, Konflikterkennung, Transaktionsrollback, Änderung historischer Teilnahmen, Audit.

HTTP-Tests mit ausschließlich synthetischen Teilnehmern: Zugriffsschutz auf Übersicht, Teilnehmer, Anwesenheiten, Import und CSV; CSRF-Verweigerung; Einrichtungscode; Login vor Einrichtung verweigert; Anmeldung, Abmeldung, Passwortwechsel, alter Zugang abgewiesen, Ratenbegrenzung; Seitenansichten; Anwesenheit speichern, veraltetes Formular verweigert; Teilnehmer/Termin neu anlegen; HTML-Escaping; Jahresübernahme ohne doppelten Vortrag; wiederholte Jahresanlage verweigert.

SQLite-Integrität erfolgreich, keine Fremdschlüsselfehler. Sicherungsroutine mit Testdaten ausgeführt und Integrität geprüft. Desktop- und 390px-Smartphoneansicht im Browser mit synthetischen Daten geprüft. Tabellen scrollen innerhalb ihres Bereichs, Seite läuft nicht horizontal über. Kein reales Admin-Passwort eingerichtet oder aus Einsatzleiter übernommen.

## Bereitstellung

Privates Paket: /home/einsatzadmin/ausbildung-dev-stage (0700), Datenimportdatei 0600. Installer mit festen SHA-256-Prüfsummen, DNS-/Zertifikatsprüfung, getrenntem FPM-Pool, separatem Nginx-vhost und täglicher lokaler Sicherung (14 Stände). Installer-Shellsyntax geprüft. Vollständige Nginx-/FPM-Konfigurationsprüfung findet vor Reload als Root im Installer statt.

Noch erforderlich: DNS-A-Record ausbildung-dev.einsatzleiter.app auf 31.70.130.202, administrative Ausführung des bereitgestellten Installers und danach HTTPS-/Dateischutzprüfung auf der realen Adresse. Abschließend Ersteinrichtung durch Sascha selbst. Bestehende Main-/DEV-Einsatzleiter-Checkouts unverändert bei 4ad8b44.

## Fortsetzung

Nach „bereit“ zuerst DNS und HTTPS-Seite read-only prüfen. Nicht Installer erneut starten, bevor geklärt ist, ob er erfolgreich ausgeführt wurde. Schutz der privaten Pfade (404), sichere Session-Cookies, eigener FPM-Pool, Datenbankinhaber und Sicherungsauftrag prüfen. Einrichtungscode nicht ausgeben; Benutzer kann ihn im eigenen Terminal mit `sudo cat /var/lib/ausbildung-dev/setup-code.txt` lesen und auf `/?page=setup` eingeben.
