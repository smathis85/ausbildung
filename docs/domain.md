# Domain und IP (Produktion)

Seit 13.10.2026 läuft die produktive Ausbildungsverwaltung unter https://ffvgs-ausbildung.de auf einer eigenen IP (31.70.156.126).

- IP: zusätzlich auf `ens6`, dauerhaft in `/etc/netplan/60-ausbildung-ip.yaml`. `50-cloud-init.yaml` (31.70.130.202 per DHCP) bleibt unverändert.
- nginx: `/etc/nginx/sites-available/ffvgs-ausbildung.de` (Marker `AUSBILDUNG_PROD_V1`, Vorlage `deploy/nginx-prod.conf`), nur an 31.70.156.126 gebunden. `www.` leitet auf die Hauptdomain weiter.
- `/etc/nginx/sites-available/00-ausbildung-ip-default` weist auf der neuen IP fremde Hostnamen ab (einsatzleiter.app ist dort nicht erreichbar).
- `ausbildung.einsatzleiter.app` bleibt auf 31.70.130.202 und leitet per 301 auf https://ffvgs-ausbildung.de weiter.
- Zertifikat: IONOS/Sectigo für ffvgs-ausbildung.de und www, `/etc/ssl/ausbildung/` (Schlüssel 600 root), gültig bis 11.04.2027. Erneuern: neue Dateien dorthin legen, `nginx -t`, `systemctl reload nginx`.
- Prüfaufrufe der Prod-Skripte gehen deshalb per `--resolve ffvgs-ausbildung.de:443:31.70.156.126` statt über 127.0.0.1.

DEV bleibt unverändert unter https://ausbildung-dev.einsatzleiter.app (31.70.130.202, Wildcard-Zertifikat).
