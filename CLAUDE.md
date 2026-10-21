# Laravel Development Standards

## ⛔ TASARIMA DOKUNMADAN ÖNCE: TASARIM.md

Bu panelin arayüzü kardeş panellerle ORTAKTIR. CSS dosyaları, sayfa kabuğu, üst
çubuk, giriş ekranı ve `ui` bileşenleri byte byte aynıdır; biri değişecekse
hepsi birlikte değişir.

**Arayüzde bir şey değiştirmeden önce `TASARIM.md` dosyasını oku.** Yeni
sınıf/renk/ölçü icat etme, var olan bileşenleri kullan, tek panelde
"iyileştirme" yapma.


## Genel Prensipler

- Laravel Best Practices takip edilmeli.
- SOLID prensiplerine uyulmalı.
- DRY (Don't Repeat Yourself) uygulanmalı.
- KISS prensibi uygulanmalı.
- Gereksiz abstraction oluşturulmamalı.
- Laravel'in kendi özellikleri tercih edilmeli, custom çözüm son seçenek olmalı.

## Controller Kuralları

Controller sadece request'i yönetmeli. Inline işlem olmamalı.

Controller içinde **bulunmamalı**:

- Business logic
- SQL sorgusu
- Büyük foreach blokları
- Validation (Form Request kullanılmalı)

## Form Request

- Tüm validation'lar Form Request içinde olmalı.

## Policy

- Authorization asla Controller içinde yazılmamalı.

## Model Kuralları

Model içinde **bulunabilir**:

- Relationships
- Scopes
- Accessors
- Casts

Model içinde **bulunmamalı**:

- Mail gönderme
- PDF üretme
- API çağırma
- Büyük business logic

## Eloquent

Raw SQL son seçenek olmalı. Tercih sırası:

1. Relationship
2. Scope
3. Query Builder
4. Raw SQL

N+1 sorgularına izin verilmemeli. Gerekli yerlerde her zaman:

- `with()`
- `load()`
- `loadMissing()`

kullanılmalı.

## Route

Route dosyasında business logic olmayacak. Route şu şekilde tanımlanmalı:

```php
Route::resource(...);
// veya
Route::controller(...);
```

## Blade

Blade içinde PHP kodu yazılmamalı.

Yanlış:

```blade
@php
@endphp
```

Yanlış:

```blade
<?php
```

Yanlış: `@if(...)` / `@foreach(...)` içinde uzun hesaplamalar.

Blade sadece görüntü oluşturmalı ve hazır veri göstermeli.

## ViewModel / Data

View'a gönderilen veri Controller veya Service tarafından hazırlanmalı.

Blade içinde yapılmamalı:

- Filtreleme
- Sıralama
- Hesaplama
- Collection işlemleri

## JavaScript

Business logic JS içinde olmamalı. JS sadece şu işleri yapmalı:

- DOM
- AJAX
- Event
- UI

Laravel tarafındaki kuralları JS tekrar etmemeli.

## AJAX

AJAX endpointleri JSON döndürmeli:

```php
return response()->json(...);
```

## API

API Resource kullanılmalı. Controller içinde:

```php
return UserResource::collection(...);
```

tercih edilmeli.

## Mass Assignment

- `fillable` veya `guarded` doğru kullanılmalı.
- Hiçbir model korumasız bırakılmamalı.

## Transactions

Birden fazla tablo güncelleniyorsa `DB::transaction()` kullanılmalı.

## Queue

Queue'ya alınmalı:

- Mail
- Bildirim
- Büyük import
- Export
- PDF
- Uzun süren işlemler

## Events

Bir işlem başka işlemleri tetikliyorsa Event kullanılmalı.

Örneğin:

```
OrderCreated
  ↓
  SendMail
  CreateInvoice
  NotifyAdmin
```

## Config

Magic string kullanılmamalı. Şunlar config/env dosyasında olmalı:

- API URL
- API Key
- Timeout
- Limit
- Dosya yolları

## Exception

- try/catch gereksiz kullanılmamalı.
- Laravel Exception Handler tercih edilmeli.

## Logging

- `dd()`, `dump()`, `var_dump()` production kodunda bulunmamalı.
- Log gerekiyorsa `Log::info()` / `Log::error()` kullanılmalı.

## Kod Stili

- PSR-12 standardı uygulanmalı.
- Anlamlı değişken isimleri kullanılmalı.
- Fonksiyonlar tek sorumluluğa sahip olmalı.
- Bir metod mümkün olduğunca 20-30 satırı geçmemeli.
- Tekrarlayan kodlar ortaklaştırılmalı.
- Vite kullanılmalı, responsive olmalı.

## Naming Convention

| Tür | Örnek |
| --- | --- |
| Controller | `PostController` |
| Service | `PostService` |
| Policy | `PostPolicy` |
| Request | `StorePostRequest` |
| Resource | `PostResource` |
| Event | `PostCreated` |
| Listener | `SendPostNotification` |
| Job | `ProcessPostJob` |

Laravel isimlendirme standartları korunmalı.

## Frontend

- Blade sadece render işlemi yapmalı.
- PHP kodu Blade içinde yazılmamalı.
- Inline JavaScript mümkün olduğunca kullanılmamalı.
- Inline CSS kullanılmamalı.
- Assetler Vite üzerinden yönetilmeli.

### Vite Yapısı

- Entrypoint'ler: `resources/css/app.css` ve `resources/js/app.js`, layout'ta `@vite([...])` ile yüklenir.
- `resources/css/app.css` sadece import barındırır; kurallar `base.css` ve `components/*.css` içinde.
- Yeni bir CSS bileşeni `resources/css/components/` altına eklenir ve `app.css`'ten import edilir.
- Geliştirme: `npm run dev` (veya `composer run dev`), production: `npm run build`.
- Blade içine `<style>` veya `<script>` blokları eklenmez; ilgili CSS/JS dosyasına taşınır.

## Güvenlik (Öncelikli)

Güvenlik bu projede diğer başlıklardan önce gelir. Bir özellik güvenli değilse
tamamlanmış sayılmaz. Aşağıdaki açık sınıfları her yeni ekranda tekrar
kontrol edilir.

### Kimlik ve yetki

- Her rota ya `auth` altında olacak ya da neden olmadığı yorumla açıklanacak.
- Her controller `HasMiddleware` ile `can:` tanımlar veya `authorize()` çağırır.
  Yetkisiz bırakılmış tek bir controller bile kabul edilmez.
- Dış dünyaya etki eden işlemler (ör. e-posta gönderme, dış servise istek
  atma) ayrı bir yetki ister; "görüntüle" yetkisi bunları
  kapsamaz.
- Kullanıcı rolünü yalnızca yönetici değiştirebilir. "Kullanıcıları düzenle"
  yetkisi, kendini yönetici yapma yetkisi değildir.

### Nesne erişimi (IDOR)

- İç içe rotalarda alt kayıt üst kayıt üzerinden çözülür: `scopeBindings()`.
  Bunsuz A kaydında yetkisi olan biri B kaydının alt satırını değiştirebilir.
- Liste sorguları kullanıcıya göre daraltılır (`visibleTo($user)` gibi bir
  scope ile); "hepsini getir sonra ekranda gizle" güvenlik değildir.
- Sıralı birincil anahtar dışarı ÇIKMAZ; URL, sorgu parametresi, form değeri ve
  DOM kimliği uuid taşır.

### Aynı satıra giden iki yol

Bir veriyi değiştirmenin iki yolu varsa İKİSİ DE korunmalı: aynı alanı
yazan iki ekrandan yalnızca birinde doğrulama varsa ikinci yol korumayı
sessizce atlar. Yeni bir ekran eklerken sorulacak soru: "bu alanı yazan başka bir yol var mı,
o da aynı kuralı uyguluyor mu?" Kural kopyalanmaz, PAYLAŞTIRILIR.

### Veri

- Parola, jeton ve anahtar alanları `encrypted` cast ile saklanır ve `$hidden`
  içindedir; hiçbir ekranda ve JSON yanıtında basılmaz. API anahtarları
  koda ve depoya HİÇ girmez: yalnızca kurulumun ortamında (`.env` ya da
  platformun gizli değişkenleri) durur; `.env` hem `.gitignore` hem `.dockerignore` içindedir.
- Toplu atama `$fillable` ile sınırlıdır; `$request->all()` kullanılmaz.
- Oturum çerezi `Secure` işaretlidir.

### Denetim

Yeni bir ekran veya uç eklendiğinde şunlar çalıştırılır ve çıktısı boş olmalıdır:

```
# Yetkisiz controller. İstisna bilinçliyse sınıf docblock'una
# "Yetkilendirme: <neden>" satırı yazılır; denetim onu kabul eder.
for f in app/Http/Controllers/*.php app/Http/Controllers/*/*.php; do
  grep -q "middleware()\|authorize(\|Yetkilendirme:" $f || echo "$f"
done

# auth dışı rota. API uçları "auth:api" taşır; ikisi de kabul edilir.
php artisan route:list --except-vendor --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if not any(m.startswith('auth') for m in (r.get('middleware') or [])):
        print(r['uri'], r.get('name'))
"
```

Her düzeltme bir regresyon testiyle kilitlenir: açığın kapandığını değil,
bir daha açılamayacağını kanıtlarız.

## Riskli İşlemler (Onay Zorunlu)

Riskli işlemler **açıkça söylenmediği sürece asla yapılmaz**; her durumda önce onay alınır.

Onay gerektiren işlemler:

- Veritabanı değişiklikleri: `migrate`, `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed`
- Veri silme veya toplu güncelleme (`delete`, `truncate`, toplu `update`)
- Dosya/dizin silme veya üzerine yazma
- `.env`, config ve deploy ayarlarında değişiklik
- Git işlemleri: `commit`, `push`, `reset --hard`, `checkout -f`, branch silme
- Composer/NPM paket ekleme, kaldırma veya güncelleme
- Cache/queue/storage temizleme komutları (`optimize:clear`, `queue:flush` vb.)
- Production ortamına yönelik her türlü işlem
- Dış servislere gerçek istek gönderen işlemler (mail, ödeme, SMS, webhook)

Kural: Emin değilsen **yapma, sor**. Onay tek bir işlem için geçerlidir, sonraki işlemleri kapsamaz.

## Kod Üretim Kuralları (Claude Code İçin)

- Laravel'in yerleşik özelliklerini yeniden icat etme.
- Yeni bir dosya oluşturmadan önce mevcut yapıyı incele ve yeniden kullan.
- Gereksiz paket önerme.
- Gereksiz helper fonksiyonları oluşturma.
- Her değişiklik mevcut mimariyle uyumlu olsun.
- Kod üretmeden önce mevcut proje yapısını analiz et.
- Oluşturulan kod PSR-12 uyumlu olsun.
- Her yeni özellikte güvenlik (Policy, Validation, CSRF, Mass Assignment) kontrolü yap.
- Performans açısından N+1 sorgularını önle ve gereksiz veritabanı sorgularından kaçın.
- Gerekli durumlarda Feature ve Unit testleri de üret.
- Görsel testleri atlama; Playwright + Chromium ile görsel testleri de yap.

## Bu Panele Özel

### Nerede çalışıyor

Panel konteynerde çalışır: kurulum deponun kökündeki `docker-compose.yml`
(Compose standardı: MariaDB ve redis ayrı servis, panel yalnız HTTP; bkz.
`docker/README.md`). Sunucuya **ne PHP ne composer ne node** kurulur: her şey
imajın içinde, kod imaja gömülüdür.

Kod bu depoda (`main`); her push'ta GitHub Actions imajı derleyip
`ghcr.io/mustafa-ozen95/syd` altına koyar (`sha-xxxxxxx`, `latest`).

### Sınamalar nasıl koşar

Depoyu imajın içine bağlayıp phpunit'i oradan çağırarak (entrypoint atlanır,
yoksa veritabanı açılışı devreye girer):

```sh
docker run --rm -u 0 -v "$PWD":/uygulama -w /uygulama --entrypoint php \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= -e APP_ENV=testing \
  -e APP_CONFIG_CACHE=/tmp/yok.php -e APP_KEY=base64:<32 bayt> \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  syd:<sürüm> vendor/bin/phpunit --no-coverage
```

`RefreshDatabase` kullanan sınamalar için imajda **pdo_sqlite yok**; önce
`apt-get install -y php8.5-sqlite3`. İmajın ortam değişkenleri `phpunit.xml`'i
yener, bu yüzden yukarıdaki `-e` satırları şart.

### Kimlik ve yetki

- Giriş iki yoldan olur: panelin kendi **e-posta/parolası** ve Kimlik Yönetim
  Merkezi (OAuth + PKCE). Merkez kapalıyken yerel giriş çalışmaya devam eder —
  panel merkeze bağımlı değildir. Merkezle girişte **parola panele hiç
  uğramaz**.
- **Kimin gireceğine MERKEZ karar verir.** Panelde yerel bir "izinli roller"
  listesi YOKTUR ve olmamalı: aynı karar iki yerde dursa biri değişince öbürü
  sessizce eski kalırdı. Merkez `/oauth/kullanici` ucunda yetkisiz rolü
  **403 + `kod: rol_yetkisiz`** ile çevirir; panel bunu "merkez cevap vermedi"
  hâlinden ayırır (birincisinde kapıyı kapatır, ikincisinde yerel parolaya
  düşer). Panelde tanınmayan bir kimliğe hesap açılıp açılmayacağını da merkez
  söyler (`hesap_acilabilir`).
- Kural İKİ UÇTA birden işler: girişte sorulur, sonradan rolü ya da durumu
  değişen için merkez **anında bildirim** gönderir (`POST /api/kimlik/tazele`,
  HMAC imzalı) ve panel doğruyu merkezden kendi sorar. Gecelik eşitleme turu
  yedektir. Yalnızca girişte bakmak, rolü sonradan değişen birinin oturumunu
  açık bırakırdı.
- **Yetkiler merkezden GELMEZ.** Bu panelde yetki paneldeki rol ve kişi
  istisnalarıyla verilir (`config/permissions.php`; ör. `yedek:download`) ve
  yöneticinin verdiği şeydir.
  Merkezde rol dağıtabilen biri, buradaki hiçbir yöneticinin haberi olmadan
  kendini burada yönetici yapamamalı.
- Yetki kontrolü tek yerde tanımlıdır: `AppServiceProvider`'daki `Gate`
  tanımları ve Policy'ler. Controller içinde elle `if ($user->role === ...)`
  YAZILMAZ.

### API

- Dışarıya açılan uçlar `routes/api.php` içinde, **`api/v1`** önekiyle ve
  `auth:api` + `throttle:api` altında. Sürüm öneki baştan var: bir alanın adı
  değiştiğinde dışarıdaki entegrasyonu kırmadan ikinci bir sürüm açmanın tek
  yolu bu.
- Kimlik `Authorization: Bearer <jeton>` başlığındadır; **oturum çerezi kabul
  edilmez** (`ApiTokenGuard`). Jetonun kendisi saklanmaz, yalnızca sha256
  özeti; sır bir kez gösterilir, kaybolursa yenisi üretilir.
- **Jeton sahibini AŞAMAZ.** Yetki, jetonun kendi listesi ile sahibinin panel
  yetkilerinin kesişimidir: `TokenAbilityGate` `Gate::before`da jetonun
  listesini sorar, gerçek yetkiyi yine panelin kendi `Gate`'i verir. Sahibinin
  yetkisi daraltılınca jeton da daralır — unutulmuş bir arka kapıya dönüşmez.
- Uç listesi ve belgesi TEK YERDE: `config/api.php`. Ekran belgeyi
  **rotalardan** üretir (`ApiDocumentation`); belgesiz bir uç eklendiğinde ya
  da rotası silinmiş bir girdi kaldığında `ApiDocumentationTest` kırılır.
  Yetki adları `config/permissions.php` sözlüğünden gelir ve her birinin bir
  `Gate` karşılığı olmak zorundadır.
- Jeton yönetimi Ayarlar'ın bir sekmesidir ve **yalnızca yöneticidedir**:
  başkası adına jeton çıkarmak, o kişinin yetkisini kalıcı bir anahtara
  dönüştürmektir. İptal SİLMEZ; kayıt için satır durur.
- Hız sınırı **jeton başına**dır, IP başına değil: aynı adresten çalışan iki
  jeton birbirini yavaşlatmamalı.

### Denetim kaydı ve hata kaydı

- Panelde yapılan her anlamlı işlem `activity_logs`'a yazılır ve satırlar
  **birbirine zincirlenir** (HMAC-SHA256): her satır bir öncekinin özetini
  taşır. Bir satırı sonradan değiştiren ya da silen kişi zinciri kırar ve
  `denetim:dogrula` bunu söyler. Anahtar APP_KEY'den AYRIDIR
  (`DENETIM_ANAHTARI`): uygulama anahtarı döndüğünde geçmiş okunmaz hâle
  gelmemeli.
- Kaydı yazan taraf `ActivityRecorder`; başka hiçbir yerden `ActivityLog`
  doğrudan yazılmaz.
- Kuyrukta ya da istek içinde patlayan hatalar `ErrorLogger` ile veritabanına
  da düşer; yönetici sunucuya girmeden görebilsin diye. Dosya günlüğü
  kapanmaz, bu ona EK bir görünümdür.

### Yorumlar

Bu depoda yorum, kodun ne yaptığını değil **neden öyle olduğunu** yazar:
hangi karar, hangi ölçüme dayanıyor. Bir düzeltmenin yanındaki ölçüm notu
(tarihsiz, kişisiz: "Junos 12.3'te X döner") silinmez — bir sonraki kişi aynı
yolu baştan denemesin diye orada.

