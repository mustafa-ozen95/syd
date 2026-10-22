#!/usr/bin/env bash
# =============================================================================
#  Sertifika ajanı — panelden gelen isteği bu sunucuda koşturur
# =============================================================================
#  Panel bir web uygulamasıdır ve TLS malzemesine YAZMAZ: ACME hesabı,
#  özel anahtar ve sertifika dosyaları sunucuda kalır. Panel yalnızca küçük
#  bir istek dosyası bırakır; asıl işi bu betik yapar.
#
#  Neden böyle: sertifika alabilen bir web uygulaması, ele geçirildiğinde
#  alan adları için geçerli sertifika üretebilir. İstek/uygulama ayrımı o
#  yetkiyi sunucuda tutuyor.
#
#  İstek dosyası belirince tetiklenir (dosya izleyici ya da zamanlanmış
#  görev). Standart Compose kurulumunda TLS'i vekil yönettiği için koşmaz.
#
#      sertifika/panel/istek.json     ← panel yazar  {"eylem":"baslat"}
#      sertifika/panel/kayitlar.json  → ajan yazar   (TXT kayıtları)
#      sertifika/panel/islem.json     → ajan yazar   (son işlemin sonucu)
# =============================================================================
set -uo pipefail
cd "$(dirname "$(readlink -f "$0")")"

PANEL=sertifika/panel
REQUEST_FILE="$PANEL/istek.json"
RECORDS_FILE="$PANEL/kayitlar.json"
STATUS_FILE="$PANEL/islem.json"

mkdir -p "$PANEL"

[[ -s "$REQUEST_FILE" ]] || exit 0

# Eylem SADECE iki değerden biri olabilir. Panelden gelen metin komut satırına
# HİÇ girmez: aşağıda doğrudan sabitlerle çağrılıyor.
ACTION=$(sed -n 's/.*"eylem"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p' "$REQUEST_FILE" | head -1)
REQUESTER=$(sed -n 's/.*"isteyen"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$REQUEST_FILE" | head -1)
rm -f "$REQUEST_FILE"

write_status() {  # durum, mesaj
    printf '{"eylem":"%s","durum":"%s","mesaj":%s,"isteyen":"%s","zaman":"%s"}\n' \
        "$ACTION" "$1" "$(printf '%s' "$2" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')" \
        "${REQUESTER:-bilinmiyor}" "$(date -Is)" > "$STATUS_FILE"
    chmod 644 "$STATUS_FILE"
}

logger -t dc-sertifika-ajan "istek: ${ACTION:-yok} (${REQUESTER:-bilinmiyor})"

case "$ACTION" in
  baslat)
    OUTPUT=$(timeout 180 ./certificate.sh dns-baslat </dev/null 2>&1)
    EXIT_CODE=$?

    if [[ $EXIT_CODE -ne 0 || ! -s dns-kayitlari.txt ]]; then
        write_status hata "Tur başlatılamadı. $(printf '%s' "$OUTPUT" | tail -3)"
        exit 0
    fi

    # Kayıtları panelin okuyacağı biçime çevir: ad + değer.
    python3 - "$RECORDS_FILE" <<'PY'
import json, re, sys, datetime

kayitlar = []
for satir in open('dns-kayitlari.txt', encoding='utf-8'):
    m = re.match(r'^(\S+)\s+([A-Za-z0-9_\-]{20,})\s*$', satir)
    if m and not satir.startswith('AD '):
        kayitlar.append({'ad': m.group(1), 'deger': m.group(2)})

json.dump(
    {'kayitlar': kayitlar, 'zaman': datetime.datetime.now().astimezone().isoformat()},
    open(sys.argv[1], 'w', encoding='utf-8'),
    ensure_ascii=False,
)
PY
    chmod 644 "$RECORDS_FILE"
    write_status tamam "TXT kayıtları üretildi."
    ;;

  tamamla)
    OUTPUT=$(timeout 420 ./certificate.sh dns-tamamla </dev/null 2>&1)
    EXIT_CODE=$?

    if [[ $EXIT_CODE -ne 0 ]]; then
        write_status hata "Sertifika alınamadı. $(printf '%s' "$OUTPUT" | sed -E 's/\x1b\[[0-9;]*m//g' | tail -4)"
        exit 0
    fi

    ./certificate.sh durum </dev/null >/dev/null 2>&1
    rm -f "$RECORDS_FILE"
    write_status tamam "Sertifika alındı ve yürürlüğe girdi."
    ;;

  *)
    write_status hata "Bilinmeyen eylem."
    ;;
esac
