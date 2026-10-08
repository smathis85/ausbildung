#!/usr/bin/env bash
# Einmalige Einrichtung von app-update auf dem Server.
# Aufruf: sudo bash install-app-update.sh app-update app-update-signing.pub
set -euo pipefail
umask 022
[[ $(id -u) == 0 ]] || { echo 'Bitte mit sudo ausführen.'; exit 1; }
script=${1:?Pfad zu app-update fehlt}
pub=${2:?Pfad zum öffentlichen Schlüssel fehlt}
grep -qE '^ssh-ed25519 [A-Za-z0-9+/=]+' "$pub" || { echo 'Kein ed25519-Public-Key.'; exit 1; }
bash -n "$script"
install -o root -g root -m 0755 "$script" /usr/local/sbin/app-update
install -d -o root -g root -m 0755 /etc/app-update
printf 'deploy namespaces="app-update" %s\n' "$(awk '{print $1" "$2}' "$pub")" > /etc/app-update/allowed_signers.new
chown root:root /etc/app-update/allowed_signers.new; chmod 0644 /etc/app-update/allowed_signers.new
mv /etc/app-update/allowed_signers.new /etc/app-update/allowed_signers
rule=/etc/sudoers.d/app-update
echo 'einsatzadmin ALL=(root) NOPASSWD: /usr/local/sbin/app-update' > "$rule.new"
chmod 0440 "$rule.new"
visudo -cf "$rule.new" >/dev/null || { rm -f "$rule.new"; echo 'sudoers-Regel ungültig.'; exit 1; }
mv "$rule.new" "$rule"
echo 'app-update eingerichtet. Test: sudo -n /usr/local/sbin/app-update (zeigt die Hilfe).'
