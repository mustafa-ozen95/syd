<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Zamanlanmış işler
|--------------------------------------------------------------------------
|
| Konteynerde bunları cron değil `php artisan schedule:work` koşturuyor
| (bkz. docker/rootfs/etc/supervisor/supervisord.conf): zamanlayıcı
| konteynerle birlikte gelir, sunucuda ayrıca cron satırı gerekmez.
|
*/

/*
 * Başarısız iş kayıtları SONSUZA KADAR birikiyor.
 *
 * Her düşen iş tabloya tam bir yığın izi yazıyor. Bir hafta geriye bakmak sorunu görmeye yeter;
 * ötesi yalnızca yedeği şişirir.
 */
Schedule::command('queue:prune-failed --hours=168')->daily();

/*
 * MERKEZDEKİ ERİŞİM DEĞİŞİKLİKLERİ — GÜNDE BİR, YEDEK OLARAK.
 *
 * Asıl yol BİLDİRİM: merkez rol ya da durum değişince anında haber veriyor
 * (bkz. routes/api.php → identity/refresh) ve panel doğruyu kendi soruyor.
 * Sık yoklamaya gerek yok — merkez zaten tetikliyor.
 *
 * Tur büsbütün kaldırılmadı: bildirim kaybolabilir (ağ, yeniden başlatma,
 * panel o an kapalı) ve kaybolan bir bildirimin bedeli KALICI ayrışma
 * olmamalı. Gecede bir hizalama, sessizce eskimiş bir erişimi yakalar.
 *
 * withoutOverlapping: merkez yavaşsa turlar üst üste binmesin.
 */
Schedule::command('identity:sync')->dailyAt('04:10')->withoutOverlapping();

/*
 * DENETİM ZİNCİRİNİ DOĞRULA.
 *
 * Zincir bir şeyi kendiliğinden korumaz; kırıldığını FARK ETMEK korur. Gece
 * bir kez baştan sona doğrulanır ve sonuç önbellekte durur; ekran oradan
 * okur, her açılışta bütün satırları yeniden özetlemez.
 */
Schedule::command('audit:verify')->dailyAt('03:30');

/*
 * YENİ SÜRÜM VAR MI — HER SABAH.
 *
 * Yalnızca bakar ve sonucu yazar; yeni sürüm varsa yönetici panelin üstünde
 * uyarı görür. Güncellemenin kendisi sunucudan yapılır. Ayarlar →
 * Güncelleme'deki düğme aynı kontrolü hemen yapar.
 */
Schedule::command('update:check')->dailyAt('06:50');
