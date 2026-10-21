# SYD — Switch Yedek Deposu

[English](README.md) · **Türkçe**

Juniper switch'lerinin bıraktığı yapılandırma yedeklerini gösteren panel.
Kardeş panellerle (KYM, EYS, NYS, SYS) aynı şablon, aynı tasarım, aynı
kimlik merkezi.

    Veri      switch yedek dizini       (konteynere /yedekler olarak SALT-OKUNUR bağlı)
    Giriş     yalnızca Kimlik Yönetim Merkezi üzerinden

## ⚠ KRİTİK

Yedek dizini çoğu zaman cihaz yapılandırmalarının **tek kopyasıdır** ve bir arıza
anında elde kalan yegâne kayıttır. Bu yüzden:

- Panelin **yazma ucu yoktur.** Silme, taşıma, yükleme yok; bir sınama bunu
  bağlıyor (`YedekEkraniTest::test_yazma_ucu_yoktur`).
- Dizin konteynere `:ro` ile bağlanır: kod bir gün yanlışlıkla yazmaya
  kalksa bile dosya sistemi izin vermez.
- Yedekleri cihazlar panelin dışındaki bir yoldan (örn. FTP) bırakır. Panel o
  yola dokunmaz; kesilirse yedek akışı sessizce durur ve haftalar sonra fark
  edilir.
- Sunucuda bir şeye dokunmadan önce yedek alınır ve yedek **ikinci bir
  makineye** kopyalanır.

## Erişim ve denetim

- Giriş **kimlik merkezinden**; kimin girebileceğine merkez karar veriyor
  (İstemciler ekranındaki rol listesi).
- Hesap merkez izin verince kendiliğinden açılıyor; panelde ayrıca
  `perm_yedek` yetkisi var ve yönetici ekranından değiştirilebiliyor.
- **Her indirme denetim kaydına düşüyor** (`yedek_indirildi`).

## Tarih dosyanın adından okunur

Cihazlar dosyayı `...conf.gz_20181006_160347` biçiminde bırakıyor. Dosya bir
yerden bir yere kopyalandığında `mtime` kopyalama anına kayıyor ve 2018 yedeği
"bugün" görünüyor. Sıralama ve gösterim addaki damgadan yapılır; damga yoksa
`mtime`'a düşülür ve satır "ad damgasız" diye işaretlenir.

## Sertifika

Standart Compose kurulumunda TLS'i panelin önündeki ters vekil bitirir;
sertifika orada yönetilir ve Ayarlar → Sertifika "bilinmiyor" der (bkz.
`docker/README.md` → Sertifika sekmesi).

Sertifika ajanını (`sertifika-ajan.sh`) çalıştıran bir kurulum, panelin istek ve
durum dosyalarını konteynere bağlar ve turu Ayarlar → Sertifika ekranından
yönetir, kardeş panellerdeki gibi:

1. **Yeni tur başlat** → panel bir istek dosyası bırakır, ajan
   (`sertifika-ajan.sh`, istek dosyası belirince tetiklenir) koşar ve
   eklenecek TXT kaydını ekrana yazar.
2. Kaydı DNS'e elle eklersiniz; ekran yayılıp yayılmadığını kendisi sorgular.
3. **Onayla** → ajan sertifikayı alır ve konteynere kurar.

Sertifikayı PANEL ALMAZ: ACME hesabı ve özel anahtar sunucuda durur, panel
yalnızca istek bırakır. Ele geçirilen bir panel, adresimiz için sertifika
üretemesin diye.

## İşletim

Kurulum deponun kökündeki `docker-compose.yml` ile (Compose standardı; her
platformda aynı dosya). Ayrıntı ve değişkenler: `docker/README.md`, `.env.example`.

    cp .env.example .env && docker compose up -d --build   # kur / güncelle
    docker compose logs -f panel                           # kayıtlar
    docker compose exec panel supervisorctl status         # servisler
