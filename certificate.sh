#!/usr/bin/env bash
# =============================================================================
#  Switch Yedek Deposu panelinin TLS sertifikası — DNS-01 ile, elle
# =============================================================================
#      ./certificate.sh dns-baslat    → eklenecek TXT kaydını yazar
#      ./certificate.sh dns-tamamla   → sertifikayı alır ve panele kurar
#      ./certificate.sh durum         → veren / bitiş / kalan gün
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
#  (bkz. certificate-agent.sh).
# =============================================================================
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"

DOMAIN="${DOMAIN:?DOMAIN verin, ör. DOMAIN=syd.ornek.test}"
EMAIL="${TLS_EMAIL:-yonetici@${DOMAIN#*.}}"
VOLUME=syd-acme

red=$'\e[31m'; green=$'\e[32m'; yellow=$'\e[33m'; bold=$'\e[1m'; reset=$'\e[0m'
heading() { printf '\n%s==> %s%s\n' "$bold" "$1" "$reset"; }
ok()  { printf '    %s✓%s %s\n' "$green" "$reset" "$1"; }
warn()  { printf '    %s!%s %s\n' "$yellow" "$reset" "$1"; }
fail()   { printf '\n%sHATA:%s %s\n\n' "$red" "$reset" "$1" >&2; exit 1; }

mkdir -p sertifika sertifika/panel

docker volume inspect "$VOLUME" >/dev/null 2>&1 || docker volume create "$VOLUME" >/dev/null
ACME=(docker run --rm -v "$VOLUME":/acme.sh -v "$PWD/sertifika":/cikti neilpang/acme.sh:latest)

case "${1:-}" in
  dns-baslat)
    heading "TXT kaydı üretiliyor"

    OUTPUT=$("${ACME[@]}" --issue --dns --server letsencrypt -m "$EMAIL" -d "$DOMAIN" \
              --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1 || true)

    # Kayıtlar dosyaya da yazılır: elle kopyalarken bir karakter kaçarsa
    # doğrulama başarısız olur ve sebebi görünmez.
    {
      printf 'DNS-01 doğrulama kaydı — %s\n\n' "$(date '+%Y-%m-%d %H:%M %Z')"
      printf '%-44s %s\n' "AD (TXT)" "DEĞER"
      printf '%s\n' "$(printf '%.0s-' {1..96})"
      printf '%s' "$OUTPUT" | awk "/Domain: /{gsub(/'/,\"\",\$NF); d=\$NF} /TXT value: /{gsub(/'/,\"\",\$NF); printf \"%-44s %s\\n\", d, \$NF}"
      printf '\nKayıt yayıldıktan sonra:  ./certificate.sh dns-tamamla\n'
    } > dns-kayitlari.txt
    chmod 644 dns-kayitlari.txt

    cat dns-kayitlari.txt | sed 's/^/    /'
    warn "Kaydı ekleyin, yayılmasını bekleyin, sonra: ./certificate.sh dns-tamamla"
    ;;

  dns-tamamla)
    heading "Sertifika alınıyor"

    # --force ŞART: acme.sh "süresi dolmamış" deyip atlıyor ve tur sessizce
    # yarıda kalıyor. Bu komutu koşturan kişi kaydı yeni eklemiştir.
    "${ACME[@]}" --renew --force --ecc -d "$DOMAIN" \
      --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1 | tail -8 | sed 's/^/    /'

    "${ACME[@]}" --install-cert --ecc -d "$DOMAIN" \
      --fullchain-file /cikti/syd.crt --key-file /cikti/syd.key >/dev/null 2>&1

    [[ -s sertifika/syd.crt && -s sertifika/syd.key ]] \
      || fail "Sertifika alınamadı. TXT kaydı yayıldı mı? (dig TXT _acme-challenge.${DOMAIN})"

    ok "sertifika alındı"

    ./docker/certificate-setup.sh sertifika/syd.crt sertifika/syd.key
    exec "$0" durum
    ;;

  durum)
    heading "Sertifika durumu"

    INFO=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$DOMAIN" 2>/dev/null \
            | openssl x509 -noout -issuer -enddate 2>/dev/null) \
      || fail "Sertifika okunamadı; panel ayakta mı?"

    ISSUER=$(printf '%s' "$INFO" | sed -n 's/^issuer=.*O *= *\([^,]*\).*/\1/p')
    EXPIRES=$(printf '%s' "$INFO" | sed -n 's/^notAfter=//p')
    REMAINING=$(( ( $(date -d "$EXPIRES" +%s) - $(date +%s) ) / 86400 ))

    printf '    %-10s %s\n' "Veren:" "${ISSUER:-kendi imzalı}"
    printf '    %-10s %s\n' "Bitiş:" "$EXPIRES"
    printf '    %-10s %s gün\n' "Kalan:" "$REMAINING"

    # Panel bu dosyayı okuyup ekranda gösterir; her istekte TLS el sıkışması
    # yapmak yerine dosyadan okumak bedava.
    printf '{"veren":"%s","bitis":"%s","kalan_gun":%s,"kontrol":"%s"}\n' \
      "${ISSUER:-kendi imzalı}" "$EXPIRES" "$REMAINING" "$(date -Is)" > sertifika/durum.json
    chmod 644 sertifika/durum.json

    [[ $REMAINING -lt 21 ]] && warn "Süre azaldı: ./certificate.sh dns-baslat ile yenileyin."
    ;;

  *)
    fail "Kullanım: $0 {dns-baslat|dns-tamamla|durum}"
    ;;
esac

printf '\n'
