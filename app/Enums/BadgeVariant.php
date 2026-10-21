<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Arayüzdeki renk varyantları. CSS sınıfı dışında bir anlam taşımaz.
 *
 * Rozet bileşeni bütün panellerde aynı dosya ve aynı sınıf adlarıdır.
 */
enum BadgeVariant: string
{
    case Green = 'green';
    case Red = 'red';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Purple = 'purple';
    case Cyan = 'cyan';
    case Orange = 'orange';
    case Pink = 'pink';
    case Gray = 'gray';
}
