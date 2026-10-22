# syd — konteyner kurulumu

Kurulum deponun kökündeki `docker-compose.yml` ile yapılır. Dosya Compose
standardındadır (compose-spec.io): aynı dosya `docker compose` ile de, Coolify,
Dokploy, Portainer gibi bir panelin "Git deposundan compose" kurulumuyla da
çalışır. Ayarların hepsi ortamdan gelir; liste ve açıklamalar `.env.example`'da.

    cp .env.example .env          # en az APP_URL ve DB_PASSWORD; chmod 600 .env
    docker compose up -d --build

Bir platformda: depoyu "compose" türünde ekleyin (dosya `docker-compose.yml`), ana
servis `panel`, port 80, sağlık yolu `/up`; `.env.example`'daki adları platformun
değişken ekranına girin.

**HTTP portu dışarı açılmaz.** Alan adını ve TLS'i önündeki vekil verir (platformun
kendisi ya da Caddy/nginx); panel isteğin HTTPS olduğunu `X-Forwarded-Proto`'dan bilir.
Vekilsiz deneme: `cp docker-compose.override.example.yml docker-compose.override.yml`
(düz HTTP, port 8080).


## Servisler

| Servis | Ne | Not |
|---|---|---|
| `panel` | nginx + php-fpm + zamanlayıcı, supervisord altında | yalnız 80 |
| `db` | MariaDB 11.4 | `DB_REPLICAS=0` ile kalkmaz (dışarıdaki veritabanı) |
| `redis` | önbellek, oturum, kuyruk | kalıcılık kapalı: yeniden başlatmada oturumlar düşer, veri düşmez |
| `tftp` | Junos görüntülerini switch'lere verir (konsoldan loader kurulumu) | `TFTP_PORT` (69/udp); cevabı da 69'dan verir |
| `swbck-pull` | switch yedeklerini başka bir FTP'den çeker, hiçbir dosya silmez | varsayılan kapalı: `SWBCK_PULL_REPLICAS=1` + `SWBCK_FTP_*` |

`panel`, `tftp` ve `swbck-pull` aynı imajdır (`docker/Dockerfile`), girişleri farklı.

`NETWORK_ISOLATED=true` konteynerleri switchlerden, PC'lerden, kimlik merkezinden ve dışarıdan yalıtır
(taşıma ya da deneme kurulumu canlı cihazlara dokunmasın diye). `swbck-pull` o zaman da
FTP'ye çıkabilir: yalnız o servis `dis` ağında.


## Hacimler

Adlar `STACK_PREFIX` ile başlar (varsayılan `syd`); aynı sunucuda ikinci kurulum onu değiştirir.

| Hacim | İçerik |
|---|---|
| `syd_storage` | `storage/app`: APP_KEY (verilmediyse), Ayarlar → Veritabanı'nın bağlantı dosyası (`database/`) |
| `syd_database` | db servisinin veri dizini |
| `syd_swbck` | switch yedekleri; panele **salt okunur** bağlanır |
| `syd_junos` | Junos görüntüleri; TFTP'ye salt okunur |
| `syd_tftp-state` | TFTP'nin ilerleme dosyası (konsol işi sayfası okur) |
| `syd_logs` | `storage/logs` |

Switch yedeklerinin dizini çoğu zaman cihaz yedeklerinin **tek kopyası**dır. Panelin yazma ucu
yok; `:ro` kod bir gün yanlışlıkla yazmaya kalksa bile dosya sisteminin izin vermemesi
için.


## ⚠ APP_KEY

Oturum çerezleri ve şifrelenen her alan buna bağlı. Boş bırakılırsa ilk açılışta
üretilir ve `storage/app/app-key.txt` içinde saklanır (değeri günlüğe basılmaz); oradan alıp
ortama yazın ve **storage hacmiyle birlikte yedekleyin**.


## Veritabanı

Panel veritabanını ortamdan alır (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`). Varsayılan compose'taki `db` servisi; parolası `DB_PASSWORD`, ilk
açılışta kullanıcı ve veritabanı bununla kurulur. Açılışta panel veritabanını en çok
üç dakika bekler, gelmezse açılmaz (konteyner yeniden dener).

Dışarıdaki ya da platformun yönettiği bir MariaDB/MySQL: `DB_*`'ı ona çevirin ve
`DB_REPLICAS=0` deyin.

Oturumun saat dilimi konteynerin saatidir (`DB_TIMEZONE`, varsayılan `+03:00`): UTC'de
çalışan bir sunucuda TIMESTAMP sütunları 3 saat kaymış okunmasın.

### Panelden taşıma (Ayarlar → Veritabanı)

Yalnız yönetici. Panel hedefi sınar: bağlantı, sürüm, hedefin boş ve şu anki
veritabanından farklı olması, göçlerin ihtiyaç duyduğu yetkiler (tablo, dizin, yabancı
anahtar, değişiklik, yazma, silme). Sınama geçerse **Taşı** konteyneri yeniden başlatır.
Açılışta panel kapalıyken şu anki veritabanı hedefe kopyalanır ve her tablonun satır
sayısı iki tarafta karşılaştırılır. Bir adım tutmazsa panel şu anki veritabanıyla açılır
ve sebep sekmede görünür.

Panelden tanımlanan bağlantı `storage/app/database/connection.json` dosyasında APP_KEY ile
şifreli durur ve ortamdaki `DB_*` değerlerinin yerine geçer. `state.json` taşımanın
aşamasını tutar ve sır içermez. Yeniden başlatmayı konteyner içindeki `dc-restart`
yapar; bunun için yeniden başlatma politikası **`unless-stopped`** olmalı (compose'ta öyle).

Taşıma bittikten sonra eski veritabanına **kendiliğinden dönülmez**; taşıma anındaki
hâliyle durur. Bilerek dönmek için: uzak veritabanının dökümünü eskisine yükleyin,
sonra `storage/app/database` altındaki `connection.json` ve `state.json`'u silip
konteyneri yeniden başlatın. Dökümü yüklemezseniz taşımadan sonraki her şey kaybolur.

APP_KEY değişirse bağlantı dosyası çözülemez ve panel açılmaz.


## İlk giriş

Bu panelde yerel hesap açılmaz: giriş kimlik merkezinden yapılır, hesap ilk merkez
girişinde kendiliğinden açılır. Kimin girebileceğine merkez karar verir.

## Güncelleme

    git pull && docker compose up -d --build

Bir platformda: yeniden dağıtın (platform depoyu çekip derler). Göçler her açılışta
`--isolated` ile koşar; iki örnek aynı anda kalksa bile bir kez çalışır.

GitHub Actions her main'de imajı derleyip `ghcr.io/mustafa-ozen95/syd` altına da
yükler (`.github/workflows/image.yml`): hazır imaj isteyen kurulum `docker/Dockerfile`'ı
derlemek yerine onu kullanabilir.

### Güncelleme kontrolü

Ayarlar → Güncelleme (yalnız yönetici) çalışan sürümü GitHub'da main dalının **son başarılı
imaj derlemesiyle** karşılaştırır: kaç commit geride, hangi commit'ler yeni. Yön de ölçülür;
çalışan sürüm son derlemeden yeniyse "güncelleme" denmez. Kontrol her sabah 06:50'de kendiliğinden
yapılır (`update:check`). Yeni sürüm varsa yöneticiler panelin üstünde uyarı görür.
Ekran **yalnızca bakar**.

- Çalışan sürüm imaja derlemede yazılır (`ARG APP_VERSION` → `APP_VERSION`; iş akışı commit'i verir).
- Depo açık: jeton gerekmez, GitHub'a jetonsuz sorulur (sınır IP başına saatte 60 istek). Depo
  özelse `UPDATE_TOKEN` salt okunur bir jeton olmalı (fine-grained, yalnız panel depoları,
  Contents ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka yeri etkilenmez.
- Çalışan sürüm derlemede imaja yazılır (`ARG APP_VERSION`, iş akışı commit'i verir). Derlemeye commit
  vermeyen platformlarda bağlamdaki `.git`'ten okunur (`docker/version-read.sh`;
  `.dockerignore` yalnız `HEAD` ve ref'leri alır).
- Panel GitHub'a çıkamıyorsa (kapalı ağ) kontrol "erişilemedi" der.


## Sertifika sekmesi

Ayarlar → Sertifika, sunucudaki sertifika ajanının durumunu gösterir ve ona tur isteği
bırakır (`/etc/dc/sertifika-durum.json`, `/etc/dc/sertifika-panel`). Standart kurulumda
TLS'i vekil yönettiği için bu dosyalar bağlı değildir; sekme "bilinmiyor" der ve
düğmeler çalışmaz.


## Sorun giderme

    docker compose logs -f panel
    docker compose exec panel supervisorctl status
    docker compose exec panel php artisan tinker
