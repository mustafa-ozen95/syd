<?php

declare(strict_types=1);

namespace App\Konsol;

use App\Konsol\Contracts\GoruntuKaynagi;
use App\Konsol\Contracts\YapilandirmaKaynagi;
use App\Konsol\Komutlar\AjanlariEsitleKomutu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * KONSOLDAN KURULUM MODÜLÜ — tek kayıt noktası.
 *
 * Başka bir panele taşımak için:
 *   1. app/Konsol, konsol-ajan/ ve resources/js/components/konsol-izle.js
 *      kopyalanır (app.js'te herCizimde'ye konsolIzle.init() eklenir),
 *   2. bootstrap/providers.php'ye bu sınıf eklenir,
 *   3. config konsol.goruntu_kaynagi ve konsol.yapilandirma_kaynagi o
 *      panelin görüntü listesine ve yedeklerine çevrilir,
 *   4. üst çubuğa "konsol.index" bağlantısı (konsol:view) eklenir,
 *   5. Dockerfile'a ajan derleme aşaması ve python3-winrm/paramiko eklenir.
 *
 * Panelden beklenenler: yetki sözlüğü (config/permissions.php, kaynak:aksiyon
 * kapıları), API jetonları (auth:api, ApiTokenContext), ActivityRecorder,
 * x-layout.app ve x-ui bileşenleri. EYS/NYS/SYD'de üçü de aynı.
 */
class KonsolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'konsol');

        config([
            'permissions.actions.ajan' => 'Ajan olarak bağlan',
            'permissions.resources.konsol' => [
                'label' => 'Konsol kurulum',
                'actions' => ['view', 'create', 'update', 'delete', 'ajan'],
            ],
            'api.endpoints' => (array) config('api.endpoints', []) + [
                'konsol.isler' => [
                    'summary' => 'Konsol: açık işler',
                    'ability' => 'konsol:ajan',
                    'description' => 'Ajanın PC\'sine atanmış açık işler, eskisi önce.',
                ],
                'konsol.olay' => [
                    'summary' => 'Konsol: adım bildir',
                    'ability' => 'konsol:ajan',
                    'description' => 'Ajanın ilerleme bildirimi; "tamam" ya da "hata" işi kapatır.',
                    'parameters' => [
                        'asama' => implode(' | ', array_keys(Models\KonsolIsi::ASAMALAR)),
                        'mesaj' => 'serbest metin (en çok 2000)',
                    ],
                ],
                'konsol.cikti' => [
                    'summary' => 'Konsol: çıktı aktar',
                    'ability' => 'konsol:ajan',
                    'description' => 'Konsolda görülenler; yanıtta panelden konsola elle yazılanlar döner.',
                ],
                'konsol.yapilandirma' => [
                    'summary' => 'Konsol: yapılandırma',
                    'ability' => 'konsol:ajan',
                    'description' => 'Yüklenecek yedeğin metni (text/plain). Her verilişi denetim kaydına yazılır.',
                ],
                'konsol.ajan' => [
                    'summary' => 'Konsol: ajanı indir',
                    'ability' => 'konsol:ajan',
                    'description' => 'Derlenmiş ajan (linux | windows). PC kurulurken kendi jetonuyla çeker.',
                ],
            ],
        ]);

        $this->app->bind(GoruntuKaynagi::class, fn ($app) => $app->make(config('konsol.goruntu_kaynagi')));
        $this->app->bind(YapilandirmaKaynagi::class, fn ($app) => $app->make(config('konsol.yapilandirma_kaynagi')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadViewsFrom(__DIR__.'/views', 'konsol');
        Blade::anonymousComponentPath(__DIR__.'/views/components', 'konsol');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        /*
         * Konsol metni olduğu gibi kalır: baştaki/sondaki boşluk ve satır
         * sonu istemin parçasıdır ("login: "), elle yazılan tek boşluk da
         * loader'a girmenin yoludur.
         */
        $hamMetin = fn (Request $istek): bool => $istek->is('api/v1/konsol/*') || $istek->routeIs('konsol.girdi');
        TrimStrings::skipWhen($hamMetin);
        ConvertEmptyStringsToNull::skipWhen($hamMetin);

        /* ANLIK AJAN: PC'de yalnız açık iş varken durur (bkz. AjanlariEsitleKomutu). */
        $this->commands([AjanlariEsitleKomutu::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $zaman): void {
            $zaman->command('konsol:ajanlar')->everyMinute()->withoutOverlapping(15)->runInBackground();
        });
    }
}
