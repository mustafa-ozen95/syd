<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JunosImage;
use Illuminate\Http\JsonResponse;

/** Kurulum sayfasındaki Junos görüntüleri ve hazır komutları. */
class JunosApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'tftp' => rtrim((string) config('junos.tftp'), '/'),
            'data' => JunosImage::query()->sirali()->get()->map(fn (JunosImage $g): array => [
                'model' => $g->model,
                'surum' => $g->surum,
                'dosya' => $g->dosya,
                'komut' => $g->komut(),
            ]),
        ]);
    }
}
