# Terminbuchung (Oktober 2026)

Vom Benutzer gewünscht: Termine einstellen, auf die sich jeder ohne Passwort einbuchen kann (wie MS Bookings/cal.com), begrenzte Teilnehmerzahl, Bestätigung per E-Mail mit Link zum Stornieren und Umbuchen, Schutz gegen Spam/Bots per Code. Alles Einstellbare unter „Einstellungen“; dorthin sind auch Unterlagen, Importprüfung und Zugang gewandert (Unterreiter, Seitenadressen unverändert).

## Bedienung

- **Buchungen** (Admins): Termin mit Titel, Datum, Beginn/Ende, Ort, Beschreibung, max. Teilnehmerzahl, öffentlich ja/nein und optional eigenem Code anlegen. Je Termin Belegung, Teilnehmerliste (Druck mit Unterschriftenspalte, CSV), Person manuell eintragen, Buchung stornieren (mit/ohne Mail), Termin absagen (alle werden informiert), löschen ohne aktive Buchungen.
- **Öffentlich**: `/?page=termine` (Liste), `/?page=termin&id=…` (Formular: Name, Feuerwehr aus Liste oder „Andere“, E-Mail, ggf. Code, Datenschutzhinweis). Danach Bestätigungsmail mit Kalenderdatei und persönlichem Link `/?page=buchung&t=…`. Dort Stornieren oder Umbuchen auf einen anderen freien Termin; Umbuchen erzeugt einen neuen Link, der alte verfällt. GET-Aufrufe ändern nie etwas (Link-Vorschauen von Mailprogrammen sind harmlos).
- **Einstellungen › Terminbuchung**: freischalten, Buchungscode (Hash, nie angezeigt), Terminliste nur mit Code, Buchungsschluss und Storno-/Umbuchungsfrist (Stunden vor Beginn), max. offene Buchungen je E-Mail, Double-Opt-in mit Frist, Grenzwerte je Internetanschluss, Feuerwehr als Liste oder Freitext, Zusatztext der Bestätigung, Datenschutzhinweis, Löschfrist, Benachrichtigung an die Ausbildungsleitung.
- **Einstellungen › E-Mail**: Absender, Antwortadresse, SMTP (STARTTLS 587, SSL/TLS 465, unverschlüsselt nur an localhost), Passwort verschlüsselt (libsodium, Schlüssel `mail.key` im Datenordner, nicht in der Datenbanksicherung; nach Wiederherstellung ohne Schlüssel Passwort neu eingeben), Signatur, Testmail, Status der Warteschlange.

## Technik

- Tabellen (additiv): `app_settings`, `booking_events`, `bookings`, `booking_rate`, `mail_outbox`.
- Platzvergabe in `BEGIN IMMEDIATE`-Transaktion, keine Überbuchung. Unbestätigte Double-Opt-in-Buchungen halten den Platz bis zum Ablauf.
- Verwaltungslink: 32 Byte Zufall, gespeichert nur als SHA-256.
- Schutz: Buchungscode, verstecktes Feld, Mindestzeit 3 s zwischen Formularaufruf und Absenden (Sitzung), Grenzen je IP (Buchungen/Stunde, Codes/15 min, Linkaufrufe), max. Buchungen je Adresse, CSRF, keine fremden CAPTCHAs.
- E-Mail: zuerst in `mail_outbox`, Versand nach der Antwort (`fastcgi_finish_request`), `bin/mail-queue.php` per Cron alle 5 min (wiederholt bis 5 Mal, löscht abgelaufene/alte Buchungen nach Löschfrist, versendete Mails nach 30 Tagen). SMTP direkt per Socket, da `proc_open/popen` gesperrt sind.
- Links in Mails nutzen die feste Adresse der Instanz, nie den Host-Header.
- Gebuchte Personen sind keine Teilnehmer der Ausbildung und sehen keine Ausbildungsdaten.
- `update.sh` legt `/etc/cron.d/<instanz>-mail` an und prüft `/?page=termine` sowie den Zugriffsschutz von `/?page=bookings`.

Tests: `php tests/booking.php`, `python3 tests/booking_http.py` (eigener SMTP-Testserver, synthetische Daten).
