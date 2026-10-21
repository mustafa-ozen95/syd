<?php

namespace App\Providers;

use App\Listeners\OturumAcilisiniYaz;
use App\Models\User;
use App\Services\Api\ApiTokenContext;
use App\Services\Api\ApiTokenGuard;
use App\Services\Api\TokenAbilityGate;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Yedek\YedekDeposu;
use App\View\UstCubuk;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Depo kökü YAPILANDIRMADAN gelir; sınıf onu kendi okumaz.
         * Okusaydı sınamada başka bir dizini göstermek için config'i
         * kandırmak gerekirdi — bağımlılık burada çözülünce sınama kendi
         * geçici dizinini verebiliyor.
         */
        $this->app->singleton(YedekDeposu::class, fn (): YedekDeposu => YedekDeposu::yapilandirmadan());

        /* İstek başına tek: jeton bağlamı ve kişi istisnalarının önbelleği. */
        $this->app->singleton(ApiTokenContext::class);
        $this->app->singleton(UserPermissionResolver::class);
    }

    public function boot(): void
    {
        /*
         * Panelden değiştirilen ayarları config'in ÜZERİNE BİNDİR.
         *
         * Her istekte uygulanır: satırı olmayan ayar varsayılanında kalır,
         * bir ayarı sıfırlamak satırı silmektir. config:cache sırasında
         * atlanır — yoksa veritabanındaki değerler dosyaya gömülür ve ayar
         * silindiğinde varsayılana dönmez (bkz. SettingsRepository::apply).
         */
        $this->app->make(SettingsRepository::class)->apply();

        /* Ortak çıkış için oturumun açılış anı (bkz. OturumAcilisiniYaz). */
        Event::listen(Login::class, OturumAcilisiniYaz::class);

        /*
         * SAYFALAMA PANELİN KENDİ ŞERİDİYLE ÇİZİLİR.
         *
         * Laravel'in varsayılanı `pagination::tailwind`; bu panelde Tailwind
         * yok ve şeridin içindeki SVG oklar hiçbir sınırı olmadan sayfa
         * boyunda çiziliyordu. Şerit ve biçemi EYS'den birebir geldi
         * (bkz. TASARIM.md).
         */
        Paginator::defaultView('vendor.pagination.default');
        Paginator::defaultSimpleView('vendor.pagination.default');

        /* API yalnızca jetonla konuşur; oturum çerezi kabul edilmez. */
        Auth::viaRequest('api-token', $this->app->make(ApiTokenGuard::class));

        /* Hız sınırı JETON başınadır: aynı adresten gelen iki jeton birbirini yavaşlatmamalı. */
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limit'))
            ->by($this->app->make(ApiTokenContext::class)->get()?->uuid ?? $request->ip()));

        /*
         * API jetonu kendi listesini AŞAMAZ. Bütün kapılardan ÖNCE çalışır:
         * yöneticiye ait bir jeton da yalnızca listesindekini yapar.
         */
        Gate::before(fn (User $user, string $ability): ?bool => $this->app
            ->make(TokenAbilityGate::class)
            ->check($user, $ability));

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        /*
         * YETKİ SÖZLÜĞÜNDEKİ HER ÇİFT BİR KAPI: "yedek:view", "junos:create"…
         * Karar tek yerde (UserPermissionResolver); rotalar `can:` ile sorar.
         */
        $sozluk = $this->app->make(PermissionRegistry::class);

        foreach ($sozluk->resourceKeys() as $kaynak) {
            foreach ($sozluk->actionsFor($kaynak) as $aksiyon) {
                Gate::define($kaynak.':'.$aksiyon, fn (User $user): bool => $this->app
                    ->make(UserPermissionResolver::class)
                    ->allows($user, $kaynak, $aksiyon));
            }
        }

        /* Üst çubuğun bölüm listesi (bkz. UstCubuk). */
        View::composer('components.nav.topbar', UstCubuk::class);

        /*
         * Her görünümde kullanılabilen ortak değişkenler. İstek başına tek
         * sorgu için static önbellek: bir sayfada onlarca bileşen çiziliyor
         * (örn. x-icon) ve composer her birinde yeniden tetikleniyor.
         */
        View::composer('*', function ($view) {
            static $cache = null;

            if ($cache === null) {
                $cache = ['navUser' => Auth::user()];
            }

            $view->with($cache);
        });
    }
}
