<x-layout.app :title="$is->ad.' · Konsol'">
<div class="page">
  <div class="page__inner page__inner--wide">
    <x-ui.page-head :title="$is->ad">
      <x-slot:subtitle>
        PC: {{ $is->pc?->ad ?? '—' }} · {{ $is->goruntu_etiket }} · {{ $is->adresOzeti() }} · {{ $yapilandirma }}
        · {{ $olusturan }}, {{ $acilis }} · <a href="{{ route('konsol.index') }}">Tüm işler</a>
      </x-slot:subtitle>
      <x-slot:actions>
        <a class="btn btn--sm" href="{{ route('konsol.kayit', $is) }}"><x-icon name="download" />Konsol kaydı</a>
        @if ($yetki['sil'] && $acik)
          <form method="POST" action="{{ route('konsol.kapat', $is) }}" data-confirm="{{ $kapatOnay }}"
                data-confirm-title="İşi kapat" data-confirm-variant="danger" data-confirm-reasonless>
            @csrf
            @method('PUT')
            <button type="submit" class="btn btn--danger btn--sm">Kapat</button>
          </form>
        @elseif ($yetki['sil'])
          <x-ui.delete-form :action="route('konsol.destroy', $is)" :confirm="$silOnay" title="İşi sil" reasonless />
        @endif
      </x-slot:actions>
    </x-ui.page-head>

    <x-ui.flash />

    {{--
      Canlı ekran: konsol-izle.js `akis` adresini yoklar, gelen çıktıyı
      ekrana, durumu ve adımları aşağıya yazar. Veri öznitelikte durur;
      yenilemesiz geçişten sonra da okunur.
    --}}
    <section class="panel" id="konsol-izle" data-akis="{{ route('konsol.akis', $is) }}"
             data-girdi="{{ route('konsol.girdi', $is) }}" data-acik="{{ $acik ? '1' : '0' }}">
      <header class="panel__head">
        <div class="row">
          <x-ui.badge :variant="$is->durumRengi()" data-konsol-durum>{{ $is->durumEtiketi() }}</x-ui.badge>
          <span class="text-sm" data-konsol-mesaj>{{ $is->son_mesaj }}</span>
        </div>
      </header>
      <div class="panel__body stack">
        {{-- TFTP'den inen görüntü: TFTP sunucusunun ilerleme dosyasından (konsol-izle.js doldurur). --}}
        <div class="konsol-ilerleme is-hidden" data-konsol-ilerleme>
          <div class="konsol-ilerleme__satir">
            <strong class="text-sm" data-konsol-ilerleme-metin></strong>
            <span class="text-sm text-muted" data-konsol-ilerleme-hiz></span>
          </div>
          <progress max="100" value="0" data-konsol-ilerleme-cubuk></progress>
        </div>
        <pre class="konsol-ekrani" data-konsol-ekran aria-live="off" tabindex="0">Yükleniyor…</pre>

        @if ($yetki['ekle'] && $acik)
          <form class="konsol-girdi" data-konsol-girdi>
            <input class="input input--mono" type="text" name="satir" maxlength="1000" autocomplete="off" spellcheck="false"
                   placeholder="Konsola yaz (Enter ile gider)" aria-label="Konsola yazılacak satır">
            <button type="submit" class="btn btn--primary btn--sm">Gönder</button>
            <button type="button" class="btn btn--sm" data-konsol-tus="enter">Enter</button>
            <button type="button" class="btn btn--sm" data-konsol-tus="bosluk">Boşluk</button>
            <button type="button" class="btn btn--sm" data-konsol-tus="ctrl-c">Ctrl+C</button>
            <button type="button" class="btn btn--sm" data-konsol-tus="ctrl-d">Ctrl+D</button>
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
          <tbody data-konsol-adimlar>
            <tr><td colspan="4" class="text-muted">Yükleniyor…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
</x-layout.app>
