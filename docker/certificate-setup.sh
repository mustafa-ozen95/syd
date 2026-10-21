#!/usr/bin/env bash
# =============================================================================
#  Gerçek TLS sertifikasını panele kurar
# =============================================================================
#      ./docker/certificate-setup.sh syd.crt syd.key
#
#  Compose düzeninde TLS'i panelin önündeki vekil bitirir (bkz.
#  docker-compose.yml); vekil sertifikayı kendisi yönetiyorsa bu betik
#  gerekmez. Sertifikayı panelin kendisi taşıyacaksa yeri hacimdir:
#  storage/app/tls/syd.{crt,key} (konsol ajanı kurulurken panel sertifikası
#  buradan okunur, bkz. app/ConsoleModule/config.php). Bu betik dosyaları oraya
#  koyar, izinleri düzeltir ve nginx'i yeniler — panel AYAKTA KALIR.
#
#  Sertifika nereden gelir: herhangi bir ACME istemcisiyle (ör. Let's Encrypt,
#  DNS-01 doğrulaması) alınır; .crt ve .key bu sunucuya kopyalanıp betiğe
#  verilir.
#
#  DNS-01 neden: doğrulama sunucuya bağlanarak değil DNS'e bakılarak yapılır;
#  adreslerin iç ağda olması ve 80 portunun dışarı kapalı olması engel değil.
# =============================================================================
set -euo pipefail

KONTEYNER="${KONTEYNER:-syd}"
ALAN="${ALAN:?ALAN verin, ör. ALAN=syd.ornek.test}"
HEDEF=/uygulama/storage/app/tls

kirmizi=$'\e[31m'; yesil=$'\e[32m'; sari=$'\e[33m'; bitir=$'\e[0m'
tamam() { printf '    %s✓%s %s\n' "$yesil" "$bitir" "$1"; }
uyari() { printf '    %s!%s %s\n' "$sari" "$bitir" "$1"; }
hata()  { printf '\n%sHATA:%s %s\n\n' "$kirmizi" "$bitir" "$1" >&2; exit 1; }

CRT="${1:-}"; KEY="${2:-}"
[[ -s "$CRT" && -s "$KEY" ]] || hata "Kullanım: $0 <sertifika.crt> <anahtar.key>"

command -v openssl >/dev/null || hata "openssl gerekli."

# SERTİFİKA VE ANAHTAR AYNI ÇİFTTEN Mİ?
#
# Eşleşmeyen çift nginx'i AÇILIŞTA düşürmez; el sıkışması sırasında düşürür ve
# panel "bağlantı kapandı" diye görünür. Burada bir saniyede anlaşılıyor.
CM=$(openssl x509 -noout -modulus -in "$CRT" | openssl sha256)
KM=$(openssl rsa -noout -modulus -in "$KEY" 2>/dev/null | openssl sha256) \
  || KM=$(openssl pkey -pubout -in "$KEY" | openssl sha256)
if [[ "$CM" != "$KM" ]]; then
  # RSA dışı anahtarlarda modulus karşılaştırması işlemez; açık anahtardan bak.
  C2=$(openssl x509 -noout -pubkey -in "$CRT" | openssl sha256)
  K2=$(openssl pkey -pubout -in "$KEY" | openssl sha256)
  [[ "$C2" == "$K2" ]] || hata "Sertifika ile anahtar aynı çiftten değil."
fi
tamam "sertifika ve anahtar eşleşiyor"

# ALAN ADI KAPSANIYOR MU?
if ! openssl x509 -noout -ext subjectAltName -in "$CRT" | grep -q "DNS:${ALAN}"; then
  uyari "Sertifika ${ALAN} adını KAPSAMIYOR; tarayıcı yine uyaracak."
  uyari "Kapsananlar: $(openssl x509 -noout -ext subjectAltName -in "$CRT" | tail -1 | tr -d ' ')"
fi

BITIS=$(openssl x509 -noout -enddate -in "$CRT" | cut -d= -f2)
tamam "bitiş: ${BITIS}"

docker inspect "$KONTEYNER" >/dev/null 2>&1 || hata "Konteyner yok: ${KONTEYNER}"

# Eski sertifika YEDEKLENİR: yenisi bozuksa geri dönülebilsin.
docker exec "$KONTEYNER" sh -c "[ -s ${HEDEF}/syd.crt ] && cp -a ${HEDEF}/syd.crt ${HEDEF}/syd.crt.onceki; \
                                 [ -s ${HEDEF}/syd.key ] && cp -a ${HEDEF}/syd.key ${HEDEF}/syd.key.onceki" || true

docker cp "$CRT" "${KONTEYNER}:${HEDEF}/syd.crt"
docker cp "$KEY" "${KONTEYNER}:${HEDEF}/syd.key"
docker exec "$KONTEYNER" sh -c "chown syd:syd ${HEDEF}/syd.crt ${HEDEF}/syd.key && chmod 0600 ${HEDEF}/syd.key"

# Önce SINA, sonra yenile: bozuk bir yapılandırmayla reload nginx'i düşürür.
docker exec "$KONTEYNER" nginx -t >/dev/null 2>&1 || hata "nginx yapılandırması sınamayı geçmedi; eski sertifika ${HEDEF}/syd.crt.onceki içinde."
docker exec "$KONTEYNER" nginx -s reload

tamam "sertifika kuruldu ve nginx yenilendi"
printf '\n    Doğrulama:  openssl s_client -connect %s:443 -servername %s </dev/null 2>/dev/null | openssl x509 -noout -issuer -dates\n\n' "$ALAN" "$ALAN"
