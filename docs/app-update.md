# Updates ohne sudo-Passwort (app-update)

`/usr/local/sbin/app-update` darf einsatzadmin ohne Passwort als root ausführen. Es spielt nur Paketordner ein,
deren Manifest am Mac mit dem Schlüssel `~/.ssh/app-update-signing` signiert wurde. Ein SSH-Zugang zum Server allein
reicht dafür nicht. Ziele: `ausbildung`, `ausbildung-dev`, `einsatzleiter`, `einsatzleiter-dev`. Das Paket-`update.sh`
erhält `prod` bzw. `dev` als Argument; einsatzleiter-Pakete müssen dieser Aufrufkonvention folgen.

## Einmalige Einrichtung

Am Mac:

    ssh-keygen -t ed25519 -f ~/.ssh/app-update-signing -C app-update
    scp deploy/app-update deploy/install-app-update.sh ~/.ssh/app-update-signing.pub einsatzadmin@31.70.130.202:/home/einsatzadmin/
    ssh -t einsatzadmin@31.70.130.202 'cd /home/einsatzadmin && sudo bash install-app-update.sh app-update app-update-signing.pub'

Das installiert das Programm (root, 0755), `/etc/app-update/allowed_signers` und die geprüfte Regel
`/etc/sudoers.d/app-update` (`einsatzadmin ALL=(root) NOPASSWD: /usr/local/sbin/app-update`).

## Ablauf je Update

    python3 deploy/build_update.py /tmp/ausbildung-x-dev-stage
    bash deploy/sign_update.sh /tmp/ausbildung-x-dev-stage ausbildung-dev
    # Ordner mit allen Dateien nach /home/einsatzadmin/ausbildung-x-dev-stage kopieren
    ssh einsatzadmin@31.70.130.202 'sudo -n /usr/local/sbin/app-update ausbildung-dev ausbildung-x-dev-stage'

app-update kopiert den Paketordner zuerst in einen root-eigenen Ordner. Dann prüft es die Signatur, das Ziel,
das Alter der Signatur (höchstens 24 Stunden) und die Prüfsumme jeder Datei. Erst danach führt es `update.sh` aus.
Unterordner, Verknüpfungen und nicht signierte Dateien führen zum Abbruch. Start und Ende landen im Syslog
(`journalctl -t app-update`).

Zurücknehmen: `sudo rm /etc/sudoers.d/app-update /usr/local/sbin/app-update && sudo rm -r /etc/app-update`.
