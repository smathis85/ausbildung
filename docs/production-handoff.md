# Produktionsübernahme, 24.09.2026

Explizit vom Benutzer für main/prod freigegeben. DNS ausbildung.einsatzleiter.app zeigt auf 31.70.130.202; vorhandenes Wildcard-Zertifikat passt. Noch kein eigener Produktions-vhost vorhanden (HTTP 200 vor Installation ist lediglich eine vorhandene Standard-Site und kein Nachweis der Bereitstellung).

Geprüft: gesamte Store- und HTTP-Suite auf isolierten künstlichen Daten in DEV- und Produktionsmodus. Passwortübernahme ohne Änderung, Sitzungsinvalidierung, wiederholte Übernahme wirkungslos, neuer Setupcode wenn DEV-Zugang noch nicht eingerichtet. Produktionsdarstellung ohne DEV-Kennzeichnung und mit eigenem Cookie. PHP- und Installer-Syntax fehlerfrei.

Paket liegt unter /home/einsatzadmin/ausbildung-prod-stage. Enthält keine Personendaten oder Passwörter. Administrative Ausführung durch Benutzer erforderlich, da der SSH-Zugang nur für den bestehenden Streaming-Installer passwortloses sudo besitzt. Keine generelle sudo-Freigabe nötig.

Nach „bereit“: neue Adresse via HTTPS prüfen; erwartete Ausbildungs-Anmeldeseite ohne DEV-Kennzeichnung; /private/training.sqlite, /source-import.json, /app/Store.php, /bin/manage.php, /.git/config jeweils 404. Sichere Produktionscookies, geschützte Teilnehmer-/CSV-Routen, getrennte Verzeichnisinhaber und Cronjob prüfen. DEV und Einsatzleiter-Produktion weiter erreichbar. Keinen Einrichtungscode und keine Passwort-Hashes lesen oder ausgeben. Bei noch offenem Setup kann Benutzer den Code privat am Terminal anzeigen: sudo cat /var/lib/ausbildung/setup-code.txt.

Installationsstatus: vorbereitet, auf administrativen Schritt wartend. Noch nicht als live behaupten.
