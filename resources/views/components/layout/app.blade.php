@props(['title' => null])

{{--
  Panelin kabuğu. Kardeş panellerle AYNI dosya, aynı sıra:
  şablona bu panel uymaz, bu panel şablona uyar.
--}}
<!doctype html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        {{-- Ekran kilidi (bkz. session-lock.js): hareketsiz sekme bu süre sonunda oturumu kapatır. --}}
        <meta name="oturum-suresi" content="{{ (int) config('session.lifetime') }}">
        <meta name="oturum-cikis" content="{{ route('logout') }}">
        <meta name="oturum-giris" content="{{ route('login') }}">
    @endauth

    {{--
        Tema çerezinin alan adı. Merkez ve paneller ayrı köklerdir; üst alan
        adı verilince seçim hepsinde paylaşılır (bkz. config/theme.php).
    --}}
    <meta name="tema-alan" content="{{ config('theme.cerez_alani') }}">
    <meta name="robots" content="noindex, nofollow">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    {{--
        YAZI TİPİ ŞABLONUN KAYNAĞINDAN GELİR.

        Bu panel onları vite eklentisiyle paketliyordu ve üretilen CSS sayfaya
        HİÇ BAĞLANMIYORDU: tarayıcı sistem fontuna düşüyor, panel kardeşlerine
        benzemiyordu (sayfada tek bir font bağlantısı yoktu). Şablon neyi
        nasıl yüklüyorsa aynısı.
    --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="shell">
    <x-nav.topbar />
    <x-ui.certificate-warning />
    <x-ui.update-warning />

    {{ $slot }}
</div>

<x-ui.toast-host />
<x-ui.confirm-modal />
</body>
</html>
