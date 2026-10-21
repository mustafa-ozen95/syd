#!/usr/bin/env python3
"""
Konsol kurulum — WinRM taşıyıcısı (kardeş paneldeki resources/bin/winrm-runner.py'nin aynısı).

Sunucu `AllowUnencrypted=false` iken mesaj NTLM oturum anahtarıyla
mühürlenmeli; bunu olgun istemci (pywinrm) yapar.

Sözleşme — hepsi standart girdi/çıktıdan, hiçbir sır komut satırında değil:

  girdi : {"action":"verify"|"run", "host":..., "port":5985,
           "username":..., "password":..., "program":..., "arguments":[...],
           "env":{"AD":"deger"}, "timeout":60}
  çıktı : {"ok":true, "status":0, "stdout":"<base64>", "stderr":"<base64>"}
          {"ok":false, "error":"..."}

Sırlar `env` ile gider: WS-Man kabuğunun ortam değişkeni hedef makinenin
komut satırında görünmez (pywinrm 0.3.0'da standart girdi yolu yok).
"""

import base64
import json
import sys

try:
    from winrm.protocol import Protocol
except Exception as e:  # pragma: no cover - kurulum eksikse anlaşılır mesaj
    print(json.dumps({"ok": False, "error": f"pywinrm yüklü değil: {e}"}))
    sys.exit(0)


def unicode_kullanici_adi():
    """
    ntlm-auth 1.4 (Debian bookworm) ilk NTLM mesajından UNICODE bayrağını
    siliyor; sunucu da ASCII'ye düşüyor ve "OPERATÖR" gibi Türkçe harfli bir
    kullanıcı adı "UnicodeEncodeError" ile hiç gönderilemiyor. Bayrak geri konur: Windows UNICODE'u her zaman
    destekler, ad UTF-16LE gider.
    """
    import struct
    from ntlm_auth import messages
    from ntlm_auth.constants import NegotiateFlags

    eski = messages.NegotiateMessage.__init__

    def yeni(self, *args, **kwargs):
        eski(self, *args, **kwargs)
        bayrak = struct.unpack("<I", self.negotiate_flags)[0]
        bayrak = (bayrak | NegotiateFlags.NTLMSSP_NEGOTIATE_UNICODE) & ~NegotiateFlags.NTLMSSP_NEGOTIATE_OEM
        self.negotiate_flags = struct.pack("<I", bayrak)

    messages.NegotiateMessage.__init__ = yeni


try:
    unicode_kullanici_adi()
except Exception:  # pragma: no cover - kütüphane değişmişse yama gerekmez
    pass


def main():
    istek = json.load(sys.stdin)
    zaman = int(istek.get("timeout", 60))
    p = Protocol(
        endpoint="http://{}:{}/wsman".format(istek["host"], istek.get("port") or 5985),
        transport="ntlm",
        username=istek["username"],
        password=istek["password"],
        message_encryption="always",
        server_cert_validation="ignore",
        operation_timeout_sec=zaman,
        read_timeout_sec=zaman + 10,
    )

    kabuk = p.open_shell(env_vars=istek.get("env") or None)

    try:
        if istek.get("action") == "verify":
            return {"ok": True, "status": 0, "stdout": "", "stderr": ""}

        komut = p.run_command(kabuk, istek["program"], istek.get("arguments", []))
        try:
            cikti, hata, kod = p.get_command_output(kabuk, komut)
        finally:
            p.cleanup_command(kabuk, komut)

        return {
            "ok": True,
            "status": int(kod),
            "stdout": base64.b64encode(cikti).decode(),
            "stderr": base64.b64encode(hata).decode(),
        }
    finally:
        p.close_shell(kabuk)


if __name__ == "__main__":
    try:
        print(json.dumps(main()))
    except Exception as hata:  # noqa: BLE001 - her hata çağırana JSON olarak döner
        print(json.dumps({"ok": False, "error": f"{type(hata).__name__}: {hata}"}))
