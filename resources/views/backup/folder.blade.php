@use('Illuminate\Support\Number')
@use('App\Services\Backup\BackupName')

<x-layout.app :title="$klasor">
<div class="page">
  <div class="page__inner page__inner--wide">
    <header class="page-head">
      <div class="page-head__text">
        <p class="page-head__subtitle">
          <a href="{{ route('yedek.index') }}">← Cihazlar</a>
        </p>
        <h1>{{ $klasor }}</h1>
        <p class="page-head__subtitle">
          En yeni yedek üstte. Tarih dosyanın ADINDAN okunur; kopyalanırken değişen dosya tarihinden değil.
        </p>
      </div>
      <div class="page-head__actions">
        <form method="get" action="{{ route('yedek.klasor', $klasor) }}" class="row row--wrap filter-bar">
          <input class="input filter" type="search" name="ara" value="{{ $arama }}"
                 placeholder="Dosya adı" aria-label="Dosya ara">
          <button type="submit" class="btn btn--sm"><x-icon name="search" />Ara</button>
        </form>
      </div>
    </header>

    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title">Yedekler</h2>
        </div>
        <span class="badge">{{ number_format($toplam, 0, ',', '.') }}</span>
      </header>

      <div class="panel__body panel__body--flush">
        @if ($dosyalar->isEmpty())
          <x-ui.empty
            title="{{ $arama !== '' ? 'Eşleşen dosya yok' : 'Bu klasör boş' }}"
            text="{{ $arama !== '' ? 'Aramayı değiştirip tekrar deneyin.' : 'Cihaz bu klasöre henüz yedek bırakmamış.' }}" />
        @else
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Dosya</th>
                  <th>Tür</th>
                  <th>Boyut</th>
                  <th>Tarih</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @foreach ($dosyalar as $dosya)
                  <tr>
                    <td data-label="Dosya" class="satir-baslik table__cell--mono">{{ $dosya['ad'] }}</td>
                    <td data-label="Tür"><span class="badge">{{ BackupName::tur($dosya['ad']) }}</span></td>
                    <td data-label="Boyut" class="table__cell--mono">{{ Number::fileSize($dosya['boyut'], 1) }}</td>
                    <td data-label="Tarih" class="table__cell--mono">
                      {{ $dosya['zaman'] ? date('d.m.Y H:i', $dosya['zaman']) : '—' }}
                      @unless ($dosya['damgali'])
                        {{-- Adında damga yok: gösterilen tarih dosyanın kendi tarihi. --}}
                        <span class="badge badge--gray" title="Tarih dosya adından okunamadı; dosyanın değiştirilme tarihi gösteriliyor">ad damgasız</span>
                      @endunless
                    </td>
                    <td>
                      <div class="table__row-actions">
                        @if ($indirebilir)
                          <a href="{{ route('yedek.indir', $dosya['yol']) }}" class="btn btn--sm">
                            <x-icon name="download" />İndir
                          </a>
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @if ($dosyalar->hasPages())
            <div class="panel__body">
              {{ $dosyalar->withQueryString()->links() }}
            </div>
          @endif
        @endif
      </div>
    </section>
  </div>
</div>
</x-layout.app>
