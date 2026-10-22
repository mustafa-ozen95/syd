{{--
    Üst çubuk — kardeş panellerdekiyle aynı yapı: marka, bölümler, boşluk,
    tema düğmesi, hesap menüsü.

    Bölüm listesi TEK YERDE (App\View\TopBar) ve görünürlük yetkiye bağlı;
    şablon yalnızca hazır listeyi çizer.
--}}

<header class="topbar">
    {{-- Ad tek yerden gelir: config('app.name'). --}}
    <a href="{{ route('backup.index') }}" class="topbar__mark"
       title="{{ config('app.name') }}">{{ config('app.name') }}</a>

    <nav class="topbar__nav" aria-label="Bölümler">
        @foreach ($bolumler as $bolum)
            <a href="{{ $bolum['url'] }}" class="topbar__link"
               @if ($bolum['active']) aria-current="page" @endif>
                {{ $bolum['label'] }}
                @if (($bolum['rozet'] ?? 0) > 0)
                    <span class="badge badge--red">{{ $bolum['rozet'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <span class="topbar__spacer"></span>

    <button type="button" class="btn btn--ghost btn--icon btn--sm" data-theme-toggle aria-label="Temayı değiştir">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"
                  stroke-linecap="round" />
        </svg>
    </button>

    @if ($kullanici)
        <div class="user-menu" data-menu>
            <button type="button" class="user-menu__button" data-menu-open
                    aria-haspopup="true" aria-expanded="false" aria-label="Hesap menüsü">
                <span class="topbar__avatar" aria-hidden="true">{{ \App\Support\Initials::al($kullanici->name) }}</span>
                <span class="topbar__name">{{ $kullanici->name }}</span>
                <svg class="user-menu__arrow" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                    <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"
                          stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <div class="user-menu__list" data-menu-list hidden>
                <a href="{{ route('profile.show') }}" class="user-menu__item">Hesabım</a>
                <a href="{{ route('logout') }}" class="user-menu__item user-menu__item--logout">Çıkış</a>
            </div>
        </div>
    @endif
</header>
