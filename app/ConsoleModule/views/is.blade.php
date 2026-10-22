<x-layout.app :title="$is->ad.' · Konsol'">
<div class="page">
  <div class="page__inner page__inner--wide">
    <x-ui.page-head :title="$is->ad">
      <x-slot:subtitle>
        PC: {{ $is->pc?->ad ?? '—' }} · {{ $is->goruntu_etiket }} · {{ $is->adresOzeti() }} · {{ $yapilandirma }}
        · {{ $olusturan }}, {{ $acilis }} · <a href="{{ route('console.index') }}">Tüm işler</a>
      </x-slot:subtitle>
      <x-slot:actions>
        <a class="btn btn--sm" href="{{ route('console.log', $is) }}"><x-icon name="download" />Konsol kaydı</a>
        @if ($yetki['sil'] && $acik)
          <form method="POST" action="{{ route('console.close', $is) }}" data-confirm="{{ $kapatOnay }}"
                data-confirm-title="İşi kapat" data-confirm-variant="danger" data-confirm-reasonless>
            @csrf
            @method('PUT')
            <button type="submit" class="btn btn--danger btn--sm">Kapat</button>
          </form>
        @elseif ($yetki['sil'])
          <x-ui.delete-form :action="route('console.destroy', $is)" :confirm="$silOnay" title="İşi sil" reasonless />
        @endif
      </x-slot:actions>
    </x-ui.page-head>

    <x-ui.flash />

    {{--
      Canlı ekran: console-watch.js `akis` adresini yoklar, gelen çıktıyı
      ekrana, durumu ve adımları aşağıya yazar. Veri öznitelikte durur;
      yenilemesiz geçişten sonra da okunur.
    --}}
    <section class="panel" id="console-watch" data-stream="{{ route('console.stream', $is) }}"
             data-input="{{ route('console.input', $is) }}" data-open="{{ $acik ? '1' : '0' }}">
      <header class="panel__head">
        <div class="row">
          <x-ui.badge :variant="$is->durumRengi()" data-console-status>{{ $is->durumEtiketi() }}</x-ui.badge>
          <span class="text-sm" data-console-message>{{ $is->son_mesaj }}</span>
        </div>
      </header>
      <div class="panel__body stack">
        {{-- TFTP'den inen görüntü: TFTP sunucusunun ilerleme dosyasından (console-watch.js doldurur). --}}
        <div class="console-progress is-hidden" data-console-progress>
          <div class="console-progress__row">
            <strong class="text-sm" data-console-progress-text></strong>
            <span class="text-sm text-muted" data-console-progress-speed></span>
          </div>
          <progress max="100" value="0" data-console-progress-bar></progress>
        </div>
        <pre class="console-screen" data-console-screen aria-live="off" tabindex="0">Yükleniyor…</pre>

        @if ($yetki['ekle'] && $acik)
          <form class="console-input" data-console-input>
            <input class="input input--mono" type="text" name="satir" maxlength="1000" autocomplete="off" spellcheck="false"
                   placeholder="Konsola yaz (Enter ile gider)" aria-label="Konsola yazılacak satır">
            <button type="submit" class="btn btn--primary btn--sm">Gönder</button>
            <button type="button" class="btn btn--sm" data-console-key="enter">Enter</button>
            <button type="button" class="btn btn--sm" data-console-key="bosluk">Boşluk</button>
            <button type="button" class="btn btn--sm" data-console-key="ctrl-c">Ctrl+C</button>
            <button type="button" class="btn btn--sm" data-console-key="ctrl-d">Ctrl+D</button>
          </form>
          <span class="field__hint">
            Ajan otomatik ilerlerken de yazılabilir; yazdığınız bir sonraki saniyede konsola gider. Satırlar denetim kaydına yazılır: parola yazmayın, parolayı işi açarken verin.
          </span>
        @endif
      </div>
    </section>

    <section class="panel">
      <header class="panel__head">
        <h2 class="panel__title">Adımlar</h2>
      </header>
      <div class="panel__body panel__body--flush">
        <table class="table">
          <thead>
            <tr><th>Zaman</th><th>Adım</th><th>Açıklama</th><th>Kaynak</th></tr>
          </thead>
          <tbody data-console-steps>
            <tr><td colspan="4" class="text-muted">Yükleniyor…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
</x-layout.app>
