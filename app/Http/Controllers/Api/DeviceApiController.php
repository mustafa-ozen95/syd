<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Api\ApiTokenContext;
use App\Services\Backup\BackupStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cihaz klasörleri ve yedekleri — salt okunur.
 *
 * Yol her zaman deponun çözümleyicisinden geçer (BackupStore::coz): kök
 * dışına çıkan, gizli ya da iç içe ad 404'tür. Cihaz adında ve dosya adında
 * eğik çizgi kabul edilmez; adres ağacı tek seviye.
 */
class DeviceApiController extends Controller
{
    public function __construct(private BackupStore $depo) {}

    public function index(Request $request): JsonResponse
    {
        $arama = mb_strtolower(trim((string) $request->query('search', '')));

        $cihazlar = $this->depo->klasorler()
            ->filter(fn (array $k): bool => $arama === '' || str_contains(mb_strtolower($k['ad']), $arama))
            ->map(fn (array $k): array => [
                'ad' => $k['ad'],
                'yedek_sayisi' => $k['adet'],
                'son_yedek' => $this->zaman($k['son']),
                'boyut' => $k['boyut'],
            ])
            ->values();

        return response()->json(['data' => $cihazlar]);
    }

    public function backups(Request $request, string $device): JsonResponse
    {
        $this->klasorOlmali($device);

        $yedekler = $this->depo->dosyalar($device, trim((string) $request->query('search', '')))
            ->map(fn (array $d): array => [
                'ad' => $d['ad'],
                'boyut' => $d['boyut'],
                'zaman' => $this->zaman($d['zaman']),
                'zaman_addan' => $d['damgali'],
            ])
            ->values();

        return response()->json(['cihaz' => $device, 'data' => $yedekler]);
    }

    /** Her indirme kayda geçer; jetonla gelindiyse jetonun adıyla. */
    public function download(string $device, string $file, ActivityRecorder $kayit, ApiTokenContext $jeton): BinaryFileResponse
    {
        $this->klasorOlmali($device);
        abort_if(str_contains($file, '/'), 404);

        $bulunan = $this->depo->dosya($device.'/'.$file);
        abort_if($bulunan === null, 404);

        $kayit->kaydet('yedek_indirildi', 'Yedek indirildi (API · '.($jeton->get()?->name ?? '—').'): '.$device.'/'.$file);

        return response()->download($bulunan->getPathname(), $bulunan->getFilename(), ['Content-Type' => 'application/octet-stream']);
    }

    private function klasorOlmali(string $device): void
    {
        $tam = str_contains($device, '/') ? null : $this->depo->coz($device);

        abort_if($tam === null || ! is_dir($tam), 404);
    }

    private function zaman(?int $an): ?string
    {
        return $an === null ? null : date(DATE_ATOM, $an);
    }
}
