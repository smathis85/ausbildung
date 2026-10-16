#!/usr/bin/env bash
# Blendet die nginx-Version nur in den vHosts der Ausbildung aus (server_tokens off). Einsatzleiter.app bleibt unverändert.
# Aufruf auf dem Server: sudo bash server-tokens.sh dev|prod
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Bitte mit sudo ausführen.'; exit 1; }
case "${1:-}" in
    dev) site=/etc/nginx/sites-available/ausbildung-dev.einsatzleiter.app; marker=AUSBILDUNG_DEV_V1; host=ausbildung-dev.einsatzleiter.app; ip=127.0.0.1;;
    prod) site=/etc/nginx/sites-available/ffvgs-ausbildung.de; marker=AUSBILDUNG_PROD_V1; host=ffvgs-ausbildung.de; ip=31.70.156.126;;
    *) echo 'Aufruf: sudo bash server-tokens.sh dev|prod'; exit 1;;
esac
[[ -f "$site" && ! -L "$site" ]] && grep -q "$marker" "$site" || { echo "Fremde oder fehlende Konfiguration $site: Abbruch."; exit 1; }
if grep -q 'server_tokens off;' "$site"; then echo "server_tokens ist in $site bereits aus. Nichts geändert."; exit 0; fi
install -d -o root -g root -m 0700 /var/backups/ausbildung-code
backup=/var/backups/ausbildung-code/$(basename "$site").before-server-tokens-$(date +%Y%m%d%H%M%S)
cp -a "$site" "$backup"
sed -i 's/server_name /server_tokens off; server_name /' "$site"
if ! nginx -t 2>/dev/null; then
    cp -a "$backup" "$site"
    echo "nginx -t fehlgeschlagen: $site wiederhergestellt, nginx nicht neu geladen."; exit 1
fi
systemctl reload nginx
server=$(curl --silent --show-error --head --resolve "$host:443:$ip" "https://$host/" | tr -d '\r' | sed -n 's/^[Ss]erver: //p')
[[ "$server" == nginx ]] || { echo "Unerwarteter Server-Header: $server"; exit 1; }
echo "Server-Header auf $host lautet jetzt nur noch: $server. Sicherung: $backup"
echo 'Einsatzleiter.app wurde nicht verändert.'
