<?php

declare(strict_types=1);

namespace App\Konsol\Uzak;

use RuntimeException;

/** Saha PC'sindeki işlem yapılamadı. Mesaj ekrana basılır; sır taşımaz. */
class UzakHata extends RuntimeException {}
