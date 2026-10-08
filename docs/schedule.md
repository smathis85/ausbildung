# Terminplan (Oktober 2026)

Vom Benutzer gewünscht: Terminplan nach Vorlage „Terminplan 2026“ pflegen und drucken, als Grundlage für Termine & Anwesenheiten; Teilnehmer dürfen ihn sehen. Samstage sind 2 Ausbildungseinheiten, alle anderen Tage 1.

- Menüpunkt „Terminplan“ (Admins): je Jahr eine Tabelle wie die Vorlage (Termin, Dauer, Ausbildungsinhalte/-methode, Fahrzeuge, Ausbilder). Je Termin: Datum, Dauer/Uhrzeit (Freitext), Thema (unterstrichen), Inhalte, Hinweise (rot), Fahrzeuge, Ausbilder (je eine Zeile).
- Ausbildungseinheiten: „Automatisch“ = Samstag 2, sonst 1; manuell 1–4. Jeder Termin legt seine Einheiten in `lessons` an (`lessons.schedule_id`). Datum und Thema werden dorthin übernommen; Anwesenheit wird wie bisher je Einheit erfasst. Vorhandene Termine am selben Datum werden übernommen (Anwesenheiten bleiben). Einheiten mit Anwesenheit werden nie gelöscht; Reduzieren der Einheiten wird dann abgelehnt, Löschen des Termins lässt sie als einzelne Termine stehen.
- Verknüpfte Termine werden nur im Terminplan bearbeitet. Freie Termine unter „Termine“ bleiben möglich.
- „Kopieren“ legt einen Termin mit gleichem Inhalt an. „In ein neues Jahr übernehmen“ kopiert den ganzen Plan; jedes Datum landet auf demselben Wochentag (nächstgelegenes Datum im Zieljahr) und muss geprüft werden. Der kopierte Plan ist zunächst ein Entwurf.
- Kopf- und Fußtext sowie die Freigabe für Teilnehmer je Jahr (Tabelle `schedule_years`, ohne Eintrag = Standardtexte, freigegeben).
- Teilnehmer: „Nächste Termine“ unter „Meine Teilnahmen“ und `/?page=schedule` (nur freigegebene Jahre, nur lesen).
- Druck: A4 hoch, Wappen oben rechts, graue Kopfzeile, Seitenzahl unten rechts, Verwaltungsspalte und Einheiten-Hinweis entfallen.
- Ersterfassung 2026: `bin/manage.php import-schedule DATEI.json` füllt nur ein leeres Jahr. Die Datei mit den Namen der Ausbilder liegt nicht in Git; `deploy/build_update.py ZIEL --schedule DATEI.json` legt sie prüfsummengebunden neben das Paket, `update.sh` importiert sie einmalig.

Tests: `php tests/schedule.php`, `python3 tests/schedule_http.py` (synthetische Daten).
