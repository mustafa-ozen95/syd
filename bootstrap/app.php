<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\KimlikDurumunuDogrula;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleApiClients;
use App\Services\ErrorLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',

        /*
         * API AYRI BİR KAPIDIR: oturum çerezi taşımaz ve CSRF beklemez.
         * Merkezin bildirim ucu buradan geçiyor; kimliğini imzadan alıyor.
         */
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TERS VEKİLE GÜVEN — konteyner kurulumunda ŞART.
         *
         * TLS önde duran vekilde (Caddy) sonlanıyor ve konteynere düz HTTP
         * geliyor. Bu satır olmadan uygulama isteği "http" sanar: ürettiği
         * bütün mutlak adresler http olur, Secure çerez gönderilmez ve
         * kimlik merkezine giden OAuth dönüş adresi kayıtlı adresle
         * eşleşmediği için takas "redirect_uri mismatch" ile reddedilir.
         *
         * Liste '*' DEĞİL: X-Forwarded-For'a güvenmek onu YAZILABİLİR kılar
         * ve denetim kaydı istemci adresine bakıyor; herkese güvenilseydi
         * kendi adresini yazan biri onu atlatırdı. Varsayılan özel ağlardır
         * (docker köprüsü 172.16/12 içinde kalır); başka bir yerleşimde
         * TRUSTED_PROXIES ile daraltılır.
         */
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env(
                'TRUSTED_PROXIES',
                '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        /*
         * PASİF HESAP İÇERİDE KALMAZ.
         *
         * `aktif` yalnızca girişte sorulsaydı erişimi kaldırılan biri, o
         * sırada oturumu açıksa gezinmeye devam ederdi.
         *
         * SIRA ÖNEMLİ: önce yerel durum, sonra merkez sorgusu. Pasif hesabı
         * dışarı atmak için merkeze gitmeye gerek yok ve merkez kapalıyken de
         * çalışmalı.
         */
        $middleware->web(append: [EnsureUserIsActive::class, KimlikDurumunuDogrula::class]);

        /*
         * Güvenlik başlıkları GENEL yığına eklenir, "web" grubuna DEĞİL.
         *
         * Gruba eklendiğinde en içte kalıyor: kimlik doğrulaması düştüğünde
         * fırlatılan istisna oradan yukarı geçip yanıtı istisna işleyicisi
         * kuruyor ve o yanıt bir daha ara katmandan geçmiyor — giriş
         * yönlendirmesi ve hata sayfaları başlıksız kalırdı.
         */
        $middleware->append(SecurityHeaders::class);

        /*
         * Kimliği DOĞRULANAMAYAN API isteği de sınırlanır.
         *
         * Jeton başına sınır kimlik doğrulamasından sonra uygulanıyor; jetonsuz
         * gelen istek oraya hiç varmıyor ve sınırsız denenebilirdi.
         */
        $middleware->api(prepend: [ThrottleApiClients::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Hatalar panele de yazılır. Dosya günlüğü kapanmaz; bu ona EK bir
         * görünüm — kuyrukta patlayan bir işi sunucuya girmeden görebilmek
         * için. Kullanıcıya yalnızca genel bir hata dönüyor ve sebebi başka
         * hiçbir yerde görünmüyor.
         */
        $exceptions->report(function (Throwable $e): void {
            app(ErrorLogger::class)->record($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Yetkisiz istek 403 sayfası yerine /yetkisiz ekranına yönlendirilir
        // (Laravel varsayılanı 403 sayfası döner).
        // Not: Illuminate\Auth\Access\AuthorizationException, Handler::prepareException()
        // tarafindan bu noktaya gelmeden once AccessDeniedHttpException'a cevriliyor.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->expectsJson() && $request->user()) {
                return redirect()->route('yetkisiz');
            }
        });
    })->create();
