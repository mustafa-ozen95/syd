<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Services\Certificate\CertificateType;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * TLS sertifikası bitmek üzereyken yöneticiye uyarı. Karar burada; şablon
 * yalnızca hazır durumu çizer.
 */
class CertificateWarning extends Component
{
    /** @var array<string, mixed> */
    public array $durum = [];

    public bool $goster;

    public function __construct(CertificateType $tur)
    {
        $this->goster = $tur->uyarilmali() && (Auth::user()?->isAdmin() ?? false);

        if ($this->goster) {
            $this->durum = $tur->durum();
        }
    }

    public function render(): View
    {
        return view('components.ui.certificate-warning');
    }
}
