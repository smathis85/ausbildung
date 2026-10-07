#!/usr/bin/env bash
# Verlegt das PHP-Upload-/Temp-Verzeichnis von /tmp (wird beim Neustart geleert)
# nach /var/lib/<instanz>/tmp. Aufruf: sudo bash fix-upload-tmp.sh prod|dev
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Bitte mit sudo ausführen.'; exit 1; }
case "${1:-}" in
    prod) name=ausbildung; owned=.ausbildung-prod-owned; marker=AUSBILDUNG_PROD_V1; host=ausbildung.einsatzleiter.app;;
    dev) name=ausbildung-dev; owned=.ausbildung-dev-owned; marker=AUSBILDUNG_DEV_V1; host=ausbildung-dev.einsatzleiter.app;;
    *) echo 'Aufruf: sudo bash fix-upload-tmp.sh prod|dev'; exit 1;;
esac
data=/var/lib/$name
pool=/etc/php/8.3/fpm/pool.d/$name.conf
[[ -f "$data/$owned" ]] || { echo 'Fremdes Datenverzeichnis.'; exit 1; }
grep -q "$marker" "$pool" || { echo 'Fremde Pool-Konfiguration.'; exit 1; }
exec 9>"/run/lock/$name-upload-tmp.lock"
flock -n 9 || { echo 'Update läuft bereits.'; exit 1; }
install -d -o "$name" -g "$name" -m 0700 "$data/tmp"
if grep -Eq "/tmp/$name\$" "$pool"; then
    install -d -o root -g root -m 0700 /var/backups/ausbildung-code
    backup="/var/backups/ausbildung-code/$name-pool-before-upload-tmp-$(date +%Y%m%d%H%M%S).conf"
    cp -a "$pool" "$backup"
    sed -i -e "s#:/tmp/$name\$##" -e "s#= /tmp/$name\$#= $data/tmp#" "$pool"
    if ! php-fpm8.3 -t; then cp -a "$backup" "$pool"; echo 'Konfiguration zurückgenommen; kein Reload.'; exit 1; fi
    systemctl reload php8.3-fpm
fi
grep -q "upload_tmp_dir\] = $data/tmp" "$pool" || { echo 'Upload-Verzeichnis nicht gesetzt.'; exit 1; }
curl --fail --silent --show-error --resolve "$host:443:127.0.0.1" "https://$host/" -o /dev/null
echo "Upload-Verzeichnis für $name liegt jetzt dauerhaft unter $data/tmp. Daten unverändert."
