<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * İlk açılış.
     *
     * HESAP AÇILMAZ. Bu panele giriş yalnızca kimlik merkezinden yapılıyor
     * ve kimin girebileceğine merkez karar veriyor; burada açılacak bir
     * "ilk yönetici" hesabı, merkezin kararını atlayan ikinci bir kapı
     * olurdu. Roller ise veriden geliyor ve göçle kuruluyor.
     */
    public function run(): void
    {
        $this->command?->info('Roller: '.Rol::count().' kayıt. Kullanıcılar merkezden gelir.');
    }
}
