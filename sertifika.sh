#!/usr/bin/env bash
# =============================================================================
#  Switch Yedek Deposu panelinin TLS sertifikası — DNS-01 ile, elle
# =============================================================================
#      ./sertifika.sh dns-baslat    → eklenecek TXT kaydını yazar
#      ./sertifika.sh dns-tamamla   → sertifikayı alır ve panele kurar
#      ./sertifika.sh durum         → veren / bitiş / kalan gün
#
#  NEDEN DNS-01: panelin adresi iç ağda. Sertifika sağlayıcısı
#  adrese bağlanarak doğrulayamaz; DNS'e bakarak doğrular. DNS sağlayıcısında
#  otomasyon yoksa kaydı bir insan ekler — bu yüzden tur iki adımlı.
#
#  NEDEN KENDİ SERTİFİKASI: başka bir sertifikaya ek ad olarak girip her
#  yenilemede dosyayı elle kopyalamak unutulmaya açıktır: kopyalanmayan
#  sertifika, süresi dolunca paneli tümüyle kapatır. Panel kendi turunu
#  kendi koşar.
#
#  ACME hesabı ve özel anahtar BU SUNUCUDA kalır; panel yalnızca istek bırakır
#  (bkz. sertifika-ajan.sh).
# =============================================================================
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"

ALAN="${ALAN:?ALAN verin, ör. ALAN=syd.ornek.test}"
EPOSTA="${TLS_EPOSTA:-yonetici@${ALAN#*.}}"
HACIM=syd-acme

kirmizi=$'\e[31m'; yesil=$'\e[32m'; sari=$'\e[33m'; kalin=$'\e[1m'; bitir=$'\e[0m'
baslik() { printf '\n%s==> %s%s\n' "$kalin" "$1" "$bitir"; }
tamam()  { printf '    %s✓%s %s\n' "$yesil" "$bitir" "$1"; }
uyari()  { printf '    %s!%s %s\n' "$sari" "$bitir" "$1"; }
hata()   { printf '\n%sHATA:%s %s\n\n' "$kirmizi" "$bitir" "$1" >&2; exit 1; }

mkdir -p sertifika sertifika/panel

docker volume inspect "$HACIM" >/dev/null 2>&1 || docker volume create "$HACIM" >/dev/null
ACME=(docker run --rm -v "$HACIM":/acme.sh -v "$PWD/sertifika":/cikti neilpang/acme.sh:latest)

case "${1:-}" in
  dns-baslat)
    baslik "TXT kaydı üretiliyor"

    CIKTI=$("${ACME[@]}" --issue --dns --server letsencrypt -m "$EPOSTA" -d "$ALAN" \
              --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1 || true)

    # Kayıtlar dosyaya da yazılır: elle kopyalarken bir karakter kaçarsa
    # doğrulama başarısız olur ve sebebi görünmez.
    {
      printf 'DNS-01 doğrulama kaydı — %s\n\n' "$(date '+%Y-%m-%d %H:%M %Z')"
      printf '%-44s %s\n' "AD (TXT)" "DEĞER"
      printf '%s\n' "$(printf '%.0s-' {1..96})"
      printf '%s' "$CIKTI" | awk "/Domain: /{gsub(/'/,\"\",\$NF); d=\$NF} /TXT value: /{gsub(/'/,\"\",\$NF); printf \"%-44s %s\\n\", d, \$NF}"
      printf '\nKayıt yayıldıktan sonra:  ./sertifika.sh dns-tamamla\n'
    } > dns-kayitlari.txt
    chmod 644 dns-kayitlari.txt

    cat dns-kayitlari.txt | sed 's/^/    /'
    uyari "Kaydı ekleyin, yayılmasını bekleyin, sonra: ./sertifika.sh dns-tamamla"
    ;;

  dns-tamamla)
    baslik "Sertifika alınıyor"

    # --force ŞART: acme.sh "süresi dolmamış" deyip atlıyor ve tur sessizce
    # yarıda kalıyor. Bu komutu koşturan kişi kaydı yeni eklemiştir.
    "${ACME[@]}" --renew --force --ecc -d "$ALAN" \
      --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1 | tail -8 | sed 's/^/    /'

    "${ACME[@]}" --install-cert --ecc -d "$ALAN" \
      --fullchain-file /cikti/syd.crt --key-file /cikti/syd.key >/dev/null 2>&1

    [[ -s sertifika/syd.crt && -s sertifika/syd.key ]] \
      || hata "Sertifika alınamadı. TXT kaydı yayıldı mı? (dig TXT _acme-challenge.${ALAN})"

    tamam "sertifika alındı"

    ./docker/sertifika-kur.sh sertifika/syd.crt sertifika/syd.key
    exec "$0" durum
    ;;

  durum)
    baslik "Sertifika durumu"

    BILGI=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$ALAN" 2>/dev/null \
            | openssl x509 -noout -issuer -enddate 2>/dev/null) \
      || hata "Sertifika okunamadı; panel ayakta mı?"

    VEREN=$(printf '%s' "$BILGI" | sed -n 's/^issuer=.*O *= *\([^,]*\).*/\1/p')
    BITIS=$(printf '%s' "$BILGI" | sed -n 's/^notAfter=//p')
    KALAN=$(( ( $(date -d "$BITIS" +%s) - $(date +%s) ) / 86400 ))

    printf '    %-10s %s\n' "Veren:" "${VEREN:-kendi imzalı}"
    printf '    %-10s %s\n' "Bitiş:" "$BITIS"
    printf '    %-10s %s gün\n' "Kalan:" "$KALAN"

    # Panel bu dosyayı okuyup ekranda gösterir; her istekte TLS el sıkışması
    # yapmak yerine dosyadan okumak bedava.
    printf '{"veren":"%s","bitis":"%s","kalan_gun":%s,"kontrol":"%s"}\n' \
      "${VEREN:-kendi imzalı}" "$BITIS" "$KALAN" "$(date -Is)" > sertifika/durum.json
    chmod 644 sertifika/durum.json

    [[ $KALAN -lt 21 ]] && uyari "Süre azaldı: ./sertifika.sh dns-baslat ile yenileyin."
    ;;

  *)
    hata "Kullanım: $0 {dns-baslat|dns-tamamla|durum}"
    ;;
esac

printf '\n'
