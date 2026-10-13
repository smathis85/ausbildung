#!/usr/bin/env bash
# Signiert einen fertig gebauten Paketordner für app-update (am Mac ausführen, vor dem Hochladen).
# Aufruf: bash deploy/sign_update.sh PAKETORDNER ZIEL   (ZIEL wie bei app-update, z. B. ausbildung-dev)
# Schlüssel: ~/.ssh/app-update-signing (einmalig: ssh-keygen -t ed25519 -f ~/.ssh/app-update-signing -C app-update)
set -euo pipefail
dir=${1:?PAKETORDNER fehlt}
target=${2:?ZIEL fehlt}
key=$(cd "$(dirname "${APP_UPDATE_KEY:-$HOME/.ssh/app-update-signing}")" && pwd)/$(basename "${APP_UPDATE_KEY:-$HOME/.ssh/app-update-signing}")
case "$target" in ausbildung|ausbildung-dev|einsatzleiter|einsatzleiter-dev) ;; *) echo "Unbekanntes Ziel: $target"; exit 1;; esac
[[ -f "$dir/update.sh" ]] || { echo "$dir/update.sh fehlt."; exit 1; }
cd "$dir"
rm -f manifest manifest.sig
sums=$(for f in *; do [[ -f "$f" ]] && shasum -a 256 "$f"; done)
printf 'target=%s\ncreated=%s\n%s\n' "$target" "$(date +%s)" "$sums" > manifest
ssh-keygen -Y sign -f "$key" -n app-update manifest >/dev/null
echo "Signiert für $target:"; cat manifest
