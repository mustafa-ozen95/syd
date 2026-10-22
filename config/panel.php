<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panelin kendi hesabı YOK
    |--------------------------------------------------------------------------
    |
    | Bu panelde "ilk yönetici" hesabı tanımlanmaz: giriş yalnızca kimlik
    | merkezinden yapılır ve kimin girebileceğine merkez karar verir. Burada
    | açılacak bir hesap, o kararı atlayan ikinci bir kapı olurdu.
    |
    | Herkesin paylaştığı tek bir parola, bir cihazın yapılandırmasını kimin
    | indirdiğini görünmez kılar; bu dosyanın boş kalmasının sebebi bu.
    |
    */

    'name' => env('PANEL_NAME', 'Sistem Yöneticisi'),

];
