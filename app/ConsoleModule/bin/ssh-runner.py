#!/usr/bin/env python3
"""
Konsol kurulum — SSH taşıyıcısı (Linux saha PC'si).

Sözleşme — hepsi standart girdi/çıktıdan, hiçbir sır komut satırında değil:

  girdi : {"host":..., "port":22, "username":..., "password":...,
           "fingerprint":"SHA256:..."|null, "script":"...", "timeout":60}
  çıktı : {"ok":true, "status":0, "stdout":"<base64>", "stderr":"<base64>",
           "fingerprint":"SHA256:..."}
          {"ok":false, "error":"..."}

Betik uzak makinede `bash -s` ile STANDART GİRDİDEN okunur: içindeki sırlar
(ajan jetonu) uzak makinenin süreç listesine ve diskine düşmez.

Anahtar: parmak izi verilmişse tutmayan anahtarla bağlanılmaz (parola
gönderilmeden önce). Verilmemişse ilk bağlantıdır; anahtarın izi döner,
panel saklar.
"""

import base64
import hashlib
import json
import sys

try:
    import paramiko
except Exception as e:  # pragma: no cover
    print(json.dumps({"ok": False, "error": f"paramiko yüklü değil: {e}"}))
    sys.exit(0)


class IzDenetimi(paramiko.MissingHostKeyPolicy):
    def __init__(self, beklenen):
        self.beklenen = beklenen
        self.gorulen = None

    def missing_host_key(self, client, hostname, key):
        iz = "SHA256:" + base64.b64encode(hashlib.sha256(key.asbytes()).digest()).decode().rstrip("=")
        self.gorulen = iz
        if self.beklenen and iz != self.beklenen:
            raise paramiko.SSHException(f"SSH anahtarı değişmiş: beklenen {self.beklenen}, gelen {iz}")


def main():
    istek = json.load(sys.stdin)
    zaman = int(istek.get("timeout", 60))
    denetim = IzDenetimi(istek.get("fingerprint"))
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(denetim)
    c.connect(
        istek["host"], port=int(istek.get("port") or 22),
        username=istek["username"], password=istek["password"],
        timeout=15, auth_timeout=15, banner_timeout=15,
        allow_agent=False, look_for_keys=False,
    )
    try:
        komut = "bash -s" if istek["username"] == "root" else "sudo -S -p '' bash -s"
        girdi, cikti, hata = c.exec_command(komut, timeout=zaman)
        if istek["username"] != "root":
            girdi.write(istek["password"] + "\n")
        girdi.write(istek.get("script", ""))
        girdi.channel.shutdown_write()
        out = cikti.read()
        err = hata.read()
        kod = cikti.channel.recv_exit_status()
    finally:
        c.close()
    return {
        "ok": True, "status": kod,
        "stdout": base64.b64encode(out).decode(), "stderr": base64.b64encode(err).decode(),
        "fingerprint": denetim.gorulen or istek.get("fingerprint"),
    }


if __name__ == "__main__":
    try:
        print(json.dumps(main()))
    except Exception as hata:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"{type(hata).__name__}: {hata}"}))
