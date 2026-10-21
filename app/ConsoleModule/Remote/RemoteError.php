<?php

declare(strict_types=1);

namespace App\ConsoleModule\Remote;

use RuntimeException;

/** Saha PC'sindeki işlem yapılamadı. Mesaj ekrana basılır; sır taşımaz. */
class RemoteError extends RuntimeException {}
