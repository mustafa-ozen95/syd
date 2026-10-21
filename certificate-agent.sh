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
ISTEK="$PANEL/istek.json"
KAYITLAR="$PANEL/kayitlar.json"
ISLEM="$PANEL/islem.json"

mkdir -p "$PANEL"

[[ -s "$ISTEK" ]] || exit 0

# Eylem SADECE iki değerden biri olabilir. Panelden gelen metin komut satırına
# HİÇ girmez: aşağıda doğrudan sabitlerle çağrılıyor.
EYLEM=$(sed -n 's/.*"eylem"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p' "$ISTEK" | head -1)
ISTEYEN=$(sed -n 's/.*"isteyen"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$ISTEK" | head -1)
rm -f "$ISTEK"

yaz() {  # durum, mesaj
    printf '{"eylem":"%s","durum":"%s","mesaj":%s,"isteyen":"%s","zaman":"%s"}\n' \
        "$EYLEM" "$1" "$(printf '%s' "$2" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')" \
        "${ISTEYEN:-bilinmiyor}" "$(date -Is)" > "$ISLEM"
    chmod 644 "$ISLEM"
}

logger -t dc-sertifika-ajan "istek: ${EYLEM:-yok} (${ISTEYEN:-bilinmiyor})"

case "$EYLEM" in
  baslat)
    CIKTI=$(timeout 180 ./certificate.sh dns-baslat </dev/null 2>&1)
    KOD=$?

    if [[ $KOD -ne 0 || ! -s dns-kayitlari.txt ]]; then
        yaz hata "Tur başlatılamadı. $(printf '%s' "$CIKTI" | tail -3)"
        exit 0
    fi

    # Kayıtları panelin okuyacağı biçime çevir: ad + değer.
    python3 - "$KAYITLAR" <<'PY'
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
    chmod 644 "$KAYITLAR"
    yaz tamam "TXT kayıtları üretildi."
    ;;

  tamamla)
    CIKTI=$(timeout 420 ./certificate.sh dns-tamamla </dev/null 2>&1)
    KOD=$?

    if [[ $KOD -ne 0 ]]; then
        yaz hata "Sertifika alınamadı. $(printf '%s' "$CIKTI" | sed -E 's/\x1b\[[0-9;]*m//g' | tail -4)"
        exit 0
    fi

    ./certificate.sh durum </dev/null >/dev/null 2>&1
    rm -f "$KAYITLAR"
    yaz tamam "Sertifika alındı ve yürürlüğe girdi."
    ;;

  *)
    yaz hata "Bilinmeyen eylem."
    ;;
esac
