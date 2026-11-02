<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * İşi açar. Bir PC'de tek açık iş olabilir (konsol kablosu bir tane): form
 * isteği bunu sorar ama "sor sonra yaz" iki operatör aynı anda gönderince
 * ikisini de geçirir. Asıl denetim burada, PC satırı kilitliyken yapılır.
 */
class JobOpener
{
    /** @param  array<string, mixed>  $veri  IsRequest::payload() + açan */
    public function ac(ConsolePc $pc, array $veri): ConsoleJob
    {
        return DB::transaction(function () use ($pc, $veri): ConsoleJob {
            ConsolePc::query()->whereKey($pc->id)->lockForUpdate()->first();

            if ($pc->isler()->acik()->exists()) {
                throw ValidationException::withMessages(['pc' => ConsoleJob::PC_MESGUL]);
            }

            return ConsoleJob::create($veri);
        });
    }
}
