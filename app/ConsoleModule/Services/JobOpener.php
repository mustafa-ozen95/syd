<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * İşi açar. Bir PC'de tek açık iş olabilir (konsol kablosu bir tane): form
 * isteği bunu sorar ama "sor sonra yaz" iki operatör aynı anda gönderince
 * ikisini de geçirir. Asıl denetim burada, PC satırı kilitliyken yapılır.
 *
 * Bilgisayardan seçilen Junos dosyası TFTP dizinine (junos diski) bu işin
 * adıyla yazılır; önce gizli geçici adla, bitince yerine (TFTP yarım dosya
 * vermesin). İş açılamazsa dosya silinir.
 */
class JobOpener
{
    /** @param  array<string, mixed>  $veri  IsRequest::payload() + açan */
    public function ac(ConsolePc $pc, array $veri, ?UploadedFile $goruntu = null): ConsoleJob
    {
        $gecici = $veri['gecici_goruntu'] ?? null;

        if ($goruntu !== null && $gecici !== null) {
            $this->yaz($goruntu, $gecici);
        }

        try {
            return DB::transaction(function () use ($pc, $veri): ConsoleJob {
                ConsolePc::query()->whereKey($pc->id)->lockForUpdate()->first();

                if ($pc->isler()->acik()->exists()) {
                    throw ValidationException::withMessages(['pc' => ConsoleJob::PC_MESGUL]);
                }

                return ConsoleJob::create($veri);
            });
        } catch (Throwable $e) {
            if ($gecici !== null) {
                Storage::disk('junos')->delete($gecici);
            }

            throw $e;
        }
    }

    private function yaz(UploadedFile $dosya, string $ad): void
    {
        $disk = Storage::disk('junos');
        $yazilan = '.yukleniyor-'.Str::random(16);

        try {
            $disk->putFileAs('', $dosya, $yazilan);
            $disk->move($yazilan, $ad);
        } finally {
            $disk->delete($yazilan);
        }
    }
}
