@use('Illuminate\Support\Number')

<x-layout.app title="Yedekler">
<div class="page">
  <div class="page__inner page__inner--wide">
    <header class="page-head">
      <div class="page-head__text">
        <h1>Switch Yedekleri</h1>
        <p class="page-head__subtitle">
          Cihazlar yapılandırmalarını buraya bırakıyor. Panel yalnızca okur; silme ya da değiştirme yoktur.
        </p>
      </div>
      <div class="page-head__actions">
        <form method="get" action="{{ route('yedek.index') }}" class="row row--wrap filter-bar">
          <input class="input filter" type="search" name="ara" value="{{ $arama }}"
                 placeholder="Cihaz adı" aria-label="Cihaz ara">
          <button type="submit" class="btn btn--sm"><x-icon name="search" />Ara</button>
        </form>
      </div>
    </header>

    @unless ($erisim)
      <div class="alert alert--danger alert--aralikli">
        Yedek dizini okunamıyor. Depo panele bağlı mı? (<code>{{ config('backup.kok') }}</code>)
      </div>
    @endunless

    {{--
      ÖZET ŞERİDİ. "Son yedek" asıl bilgidir: bir cihaz yedek göndermeyi
      bıraktığında klasörü hâlâ dolu görünür, fark edilen tek şey tarihtir.
    --}}
    <section class="panel">
      <div class="panel__body">
        <div class="figures">
          <div class="figure">
            <div class="figure__value">{{ $ozet['cihaz'] }}</div>
            <div class="figure__label">Cihaz</div>
          </div>
          <div class="figure">
            <div class="figure__value">{{ number_format($ozet['dosya'], 0, ',', '.') }}</div>
            <div class="figure__label">Yedek dosyası</div>
          </div>
          <div class="figure">
            <div class="figure__value">{{ Number::fileSize($ozet['boyut'], 1) }}</div>
            <div class="figure__label">Toplam boyut</div>
          </div>
          <div class="figure">
            <div class="figure__value">{{ $ozet['son'] ? date('d.m.Y', $ozet['son']) : '—' }}</div>
            <div class="figure__label">En son yedek</div>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title">Cihazlar</h2>
        </div>
        <span class="badge">{{ $klasorler->count() }}</span>
      </header>

      <div class="panel__body panel__body--flush">
        @if ($klasorler->isEmpty())
          <x-ui.empty
            title="{{ $arama !== '' ? 'Eşleşen cihaz yok' : 'Henüz yedek yok' }}"
            text="{{ $arama !== '' ? 'Aramayı değiştirip tekrar deneyin.' : 'Cihazlar yedek bıraktığında burada görünecekler.' }}" />
        @else
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Cihaz</th>
                  <th>Yedek</th>
                  <th>Boyut</th>
                  <th>Son yedek</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($klasorler as $klasor)
                  <tr>
                    <td data-label="Cihaz" class="satir-baslik">
                      <a href="{{ route('yedek.klasor', $klasor['ad']) }}">{{ $klasor['ad'] }}</a>
                    </td>
                    <td data-label="Yedek" class="table__cell--mono">{{ number_format($klasor['adet'], 0, ',', '.') }}</td>
                    <td data-label="Boyut" class="table__cell--mono">{{ Number::fileSize($klasor['boyut'], 1) }}</td>
                    <td data-label="Son yedek" class="table__cell--mono">
                      @if ($klasor['son'] === null)
                        <span class="text-muted">—</span>
                      @else
                        {{ date('d.m.Y H:i', $klasor['son']) }}
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </section>
  </div>
</div>
</x-layout.app>
