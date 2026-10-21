<x-layout.app title="Denetim kayıtları">
<div class="page">
  <div class="page__inner page__inner--wide">
    <header class="page-head">
      <div class="page-head__text">
        <h1>Denetim kayıtları</h1>
        <p class="page-head__subtitle">Kim, ne zaman, ne yaptı. Satırlar birbirine zincirlenir; değiştirilen ya da silinen satır zinciri kırar.</p>
      </div>
      <div class="page-head__actions">
        <form method="post" action="{{ route('admin.kayitlar.dogrula') }}"
              data-confirm="Bütün kayıt yeniden özetlenecek; büyük tabloda sürebilir."
              data-confirm-title="Zinciri şimdi doğrula">
          @csrf
          <button type="submit" class="btn btn--primary btn--sm">Zinciri doğrula</button>
        </form>
      </div>
    </header>

    <x-logs.tabs aktif="denetim" />

    {{--
      BÜTÜNLÜK ROZETİ: kaydın değiştirilmediğinin kanıtı.

      Her satır kendinden öncekinin HMAC özetini taşır; biri değiştirilir ya
      da silinirse ondan sonraki bütün özetler tutmaz. Rozet gecelik turun
      (denetim:dogrula) bıraktığı sonucu okur — ekran her açılışta bütün
      satırları yeniden özetlemez.
    --}}
    @if ($butunluk)
    <section class="panel">
      <div class="panel__body">
        <div class="row row--wrap">
          @if ($butunluk['saglam'])
            <span class="badge badge--green">Kayıt bütünlüğü doğrulandı</span>
          @else
            <span class="badge badge--red">KAYIT DEĞİŞTİRİLMİŞ</span>
            <span class="text-sm">İlk bozuk kayıt: #{{ $butunluk['kirik_id'] }}</span>
          @endif

          <span class="text-sm text-muted">
            {{ $butunluk['bakilan'] }} kayıt · {{ \Illuminate\Support\Carbon::parse($butunluk['zaman'])->format('d.m.Y H:i') }}
          </span>

          @if ($butunluk['korumasiz'] > 0)
            <span class="text-sm text-muted">{{ $butunluk['korumasiz'] }} kayıt zincirden önce yazılmış</span>
          @endif
        </div>
      </div>
    </section>
    @else
    <section class="panel">
      <div class="panel__body">
        <span class="text-sm text-muted">Zincir henüz doğrulanmadı — gecelik tur 03:30'da koşuyor, ya da “Zinciri doğrula” ile şimdi çalıştırın.</span>
      </div>
    </section>
    @endif

    <section class="panel">
      {{--
        Filtre çubuğu başlığın YANINDA ve alanlar yan yana: şablondaki liste
        ekranlarında da öyle. Alt alta dizildiğinde üç satır yer kaplıyor ve
        tablo aşağı itiliyordu.
      --}}
      <div class="panel__head">
        <h2 class="panel__title">Kayıtlar</h2>
        <span class="badge">{{ $kayitlar->total() }}</span>

        <form method="get" class="row row--wrap filter-bar">
          <input class="input filter" type="search" name="ara" value="{{ $ara }}"
                 placeholder="Açıklama veya kullanıcı" aria-label="Kayıtlarda ara">

          <select class="select" name="tur" aria-label="İşlem türü">
            <option value="">Tüm işlemler</option>
            @foreach ($turler as $t)
              <option value="{{ $t }}" @selected($tur === $t)>{{ $t }}</option>
            @endforeach
          </select>

          <button type="submit" class="btn btn--sm">Filtrele</button>

          @if ($tur !== '' || $ara !== '')
            <a href="{{ route('admin.kayitlar.denetim') }}" class="btn btn--ghost btn--sm">Temizle</a>
          @endif
        </form>
      </div>

      <div class="panel__body panel__body--flush">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Zaman</th>
                <th>Kullanıcı</th>
                <th>İşlem</th>
                <th>Açıklama</th>
                <th>Adres</th>
                <th>Özet</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($kayitlar as $k)
              <tr>
                <td data-label="Zaman" class="table__cell--mono">{{ $k->created_at?->format('d.m.Y H:i:s') }}</td>
                {{-- O günkü ad: hesap silinse bile satır kimin yaptığını söyler. --}}
                <td data-label="Kullanıcı">{{ $k->kullanici_adi ?? '—' }}</td>
                <td data-label="İşlem"><span class="badge">{{ $k->tur }}</span></td>
                <td data-label="Açıklama" class="satir-baslik">{{ $k->aciklama ?? '—' }}</td>
                <td data-label="Adres" class="table__cell--mono">{{ $k->ip_adresi ?? '—' }}</td>
                <td data-label="Özet" class="table__cell--mono" title="{{ $k->ozet }}">
                  {{ $k->ozet ? \Illuminate\Support\Str::substr($k->ozet, 0, 8) : 'korumasız' }}
                </td>
              </tr>
              @empty
              <tr><td colspan="6"><div class="empty">Kayıt yok.</div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{ $kayitlar->links() }}
  </div>
</div>
</x-layout.app>
