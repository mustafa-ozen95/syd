<x-layout.app title="Kurulum">
<div class="page">
  <div class="page__inner page__inner--wide">
    <x-ui.page-head title="Junos TFTP Yerel Kurulum">
      <x-slot:subtitle>
        Görüntüler {{ $tftp }} üzerinden çekilir. Komutu switch'in komut satırında (operational mode) çalıştırın;
        <code>--format</code> cihazı sıfırlar, yapılandırma silinir.
        @if ($konsolGorur)
          Konsol kablosundan kendiliğinden kurmak için <a href="{{ route('konsol.index') }}">Konsol</a>.
        @endif
      </x-slot:subtitle>
      @if ($yetki['ekle'])
        <x-slot:actions>
          <button type="button" class="btn btn--primary btn--sm" data-modal-open="goruntu-ekle"><x-icon name="plus" />Yeni görüntü</button>
        </x-slot:actions>
      @endif
    </x-ui.page-head>

    <x-ui.flash />

    <section class="panel">
      <table class="table">
        <thead>
          <tr>
            <th>Model</th>
            <th>Sürüm</th>
            <th>Komut</th>
            @if ($eylemVar)
              <th></th>
            @endif
          </tr>
        </thead>
        <tbody>
          @forelse ($satirlar as $satir)
            <tr>
              <td data-label="Model"><strong>{{ $satir['goruntu']->model }}</strong></td>
              <td data-label="Sürüm">{{ $satir['goruntu']->surum }}</td>
              <td data-label="Komut">
                <div class="row">
                  <input id="{{ $satir['kimlik'] }}" class="input input--mono" type="text" readonly
                         value="{{ $satir['komut'] }}" aria-label="{{ $satir['goruntu']->model }} kurulum komutu">
                  <button type="button" class="btn btn--sm" data-kopyala="#{{ $satir['kimlik'] }}"><x-icon name="copy" />Kopyala</button>
                </div>
              </td>
              @if ($eylemVar)
                <td>
                  <div class="table__row-actions">
                    @if ($yetki['duzenle'])
                      <button type="button" class="btn btn--sm" data-modal-open="goruntu-duzenle-{{ $satir['goruntu']->id }}"><x-icon name="edit" />Düzenle</button>
                    @endif
                    @if ($yetki['sil'])
                      <x-ui.delete-form :action="route('kurulum.goruntu.destroy', $satir['goruntu'])"
                                        confirm="{{ $satir['goruntu']->model }} ({{ $satir['goruntu']->dosya }}) listeden kaldırılacak. TFTP sunucusundaki dosyaya dokunulmaz."
                                        title="Görüntüyü sil"
                                        verify="{{ $satir['goruntu']->model }}"
                                        reasonless />
                    @endif
                  </div>
                </td>
              @endif
            </tr>
          @empty
            <tr>
              <td colspan="4" class="text-muted">Henüz görüntü yok.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </section>

    {{--
      Pencereler `.page__inner`in İÇİNDE: yenilemesiz geçiş yalnız bu öğeyi
      değiştiriyor, dışarıdaki pencere hiç gelmezdi (bkz. kullanicilar).
      Dosya adı yalnız TFTP sunucusundaki adı söyler; panel dosya yüklemez.
    --}}
    @if ($yetki['ekle'])
      <x-ui.modal id="goruntu-ekle" title="Yeni Junos görüntüsü" :open="$ekleAcik">
        <form method="POST" action="{{ route('kurulum.goruntu.store') }}">
          @csrf
          <input type="hidden" name="_form" value="ekle">
          <div class="modal__body stack">
            <x-setup.image-fields onek="yeni" :deger="$ekleDeger" :hatali="$ekleAcik" :tftp="$tftp" />
          </div>
          <x-ui.modal-actions submit="Ekle" />
        </form>
      </x-ui.modal>
    @endif

    @if ($yetki['duzenle'])
      @foreach ($satirlar as $satir)
        <x-ui.modal id="goruntu-duzenle-{{ $satir['goruntu']->id }}" :title="$satir['goruntu']->model.' — düzenle'" :open="$satir['acik']">
          <form method="POST" action="{{ route('kurulum.goruntu.update', $satir['goruntu']) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="duzenle-{{ $satir['goruntu']->id }}">
            <div class="modal__body stack">
              <x-setup.image-fields :onek="'goruntu-'.$satir['goruntu']->id" :deger="$satir['deger']" :hatali="$satir['acik']" :tftp="$tftp" />
            </div>
            <x-ui.modal-actions submit="Güncelle" />
          </form>
        </x-ui.modal>
      @endforeach
    @endif
  </div>
</div>
</x-layout.app>
