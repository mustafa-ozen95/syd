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

CONTAINER="${CONTAINER:-syd}"
DOMAIN="${DOMAIN:?DOMAIN verin, ör. DOMAIN=syd.ornek.test}"
TARGET_DIR=/uygulama/storage/app/tls

red=$'\e[31m'; green=$'\e[32m'; yellow=$'\e[33m'; reset=$'\e[0m'
ok() { printf '    %s✓%s %s\n' "$green" "$reset" "$1"; }
warn() { printf '    %s!%s %s\n' "$yellow" "$reset" "$1"; }
fail()  { printf '\n%sHATA:%s %s\n\n' "$red" "$reset" "$1" >&2; exit 1; }

CRT="${1:-}"; KEY="${2:-}"
[[ -s "$CRT" && -s "$KEY" ]] || fail "Kullanım: $0 <sertifika.crt> <anahtar.key>"

command -v openssl >/dev/null || fail "openssl gerekli."

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
  [[ "$C2" == "$K2" ]] || fail "Sertifika ile anahtar aynı çiftten değil."
fi
ok "sertifika ve anahtar eşleşiyor"

# DOMAIN ADI KAPSANIYOR MU?
if ! openssl x509 -noout -ext subjectAltName -in "$CRT" | grep -q "DNS:${DOMAIN}"; then
  warn "Sertifika ${DOMAIN} adını KAPSAMIYOR; tarayıcı yine uyaracak."
  warn "Kapsananlar: $(openssl x509 -noout -ext subjectAltName -in "$CRT" | tail -1 | tr -d ' ')"
fi

EXPIRES=$(openssl x509 -noout -enddate -in "$CRT" | cut -d= -f2)
ok "bitiş: ${EXPIRES}"

docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "Konteyner yok: ${CONTAINER}"

# Eski sertifika YEDEKLENİR: yenisi bozuksa geri dönülebilsin.
docker exec "$CONTAINER" sh -c "[ -s ${TARGET_DIR}/syd.crt ] && cp -a ${TARGET_DIR}/syd.crt ${TARGET_DIR}/syd.crt.onceki; \
                                 [ -s ${TARGET_DIR}/syd.key ] && cp -a ${TARGET_DIR}/syd.key ${TARGET_DIR}/syd.key.onceki" || true

docker cp "$CRT" "${CONTAINER}:${TARGET_DIR}/syd.crt"
docker cp "$KEY" "${CONTAINER}:${TARGET_DIR}/syd.key"
docker exec "$CONTAINER" sh -c "chown syd:syd ${TARGET_DIR}/syd.crt ${TARGET_DIR}/syd.key && chmod 0600 ${TARGET_DIR}/syd.key"

# Önce SINA, sonra yenile: bozuk bir yapılandırmayla reload nginx'i düşürür.
docker exec "$CONTAINER" nginx -t >/dev/null 2>&1 || fail "nginx yapılandırması sınamayı geçmedi; eski sertifika ${TARGET_DIR}/syd.crt.onceki içinde."
docker exec "$CONTAINER" nginx -s reload

ok "sertifika kuruldu ve nginx yenilendi"
printf '\n    Doğrulama:  openssl s_client -connect %s:443 -servername %s </dev/null 2>/dev/null | openssl x509 -noout -issuer -dates\n\n' "$DOMAIN" "$DOMAIN"
