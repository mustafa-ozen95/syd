<x-layout.app title="Konsol Kurulum">
<div class="page">
  <div class="page__inner page__inner--wide">
    <x-ui.page-head title="Konsoldan Kurulum">
      <x-slot:subtitle>
        PC'ye takılı konsol kablosundan: cihaz loader'a indirilir, Junos TFTP'den kurulur, istenirse yedeği yüklenir.
        Ajan yalnızca burada açılmış işi uygular.
      </x-slot:subtitle>
      @if ($yetki['ekle'] && $pcSecenekleri !== [])
        <x-slot:actions>
          <button type="button" class="btn btn--primary btn--sm" data-modal-open="konsol-yeni"><x-icon name="plus" />Yeni iş</button>
        </x-slot:actions>
      @endif
    </x-ui.page-head>

    <x-ui.flash />

    <x-konsol::nasil :acik="$satirlar === []" :loader-komutlari="$loaderKomutlari" :kurulum-komutu="$kurulumKomutu" :tftp="$tftp" />

    <section class="panel">
      <header class="panel__head">
        <h2 class="panel__title">İşler</h2>
      </header>
      <div class="panel__body panel__body--flush">
        <table class="table">
          <thead>
            <tr>
              <th>Cihaz</th>
              <th>Görüntü</th>
              <th>Durum</th>
              <th>Güncellendi</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($satirlar as $satir)
              <tr>
                <td data-label="Cihaz">
                  <a href="{{ route('konsol.show', $satir['is']) }}"><strong>{{ $satir['is']->ad }}</strong></a>
                  <div class="text-sm text-muted">PC: {{ $satir['pc'] }}</div>
                </td>
                <td data-label="Görüntü" class="text-sm">
                  {{ $satir['goruntu'] }}
                  @if ($satir['yapilandirma'] !== '')
                    <div class="text-muted">{{ $satir['yapilandirma'] }}</div>
                  @endif
                </td>
                <td data-label="Durum">
                  <x-ui.badge :variant="$satir['renk']">{{ $satir['durum'] }}</x-ui.badge>
                  @if ($satir['bekliyor'])
                    <x-ui.badge variant="yellow">Sizden bekleniyor</x-ui.badge>
                  @endif
                  @if ($satir['is']->son_mesaj)
                    <div class="text-sm text-muted">{{ $satir['is']->son_mesaj }}</div>
                  @endif
                </td>
                <td data-label="Güncellendi" class="text-sm">{{ $satir['guncellendi'] }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="4" class="text-muted">Henüz iş yok.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    @if ($yetki['pc'])
      <section class="panel">
        <header class="panel__head">
          <div>
            <h2 class="panel__title">PC'ler</h2>
            <p class="text-sm text-muted">
              Konsol kablosunun takıldığı PC'ler. Ajan anlıktır: iş açılınca panel onu PC'ye en geç bir dakikada kurar
              (Windows'ta WinRM, Linux'ta SSH), PC'de açık iş kalmayınca kaldırır. "Durum" PC'deki seri portları da gösterir.
            </p>
          </div>
          <div class="panel__actions">
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="konsol-pc-yeni"><x-icon name="plus" />PC ekle</button>
          </div>
        </header>
        <div class="panel__body panel__body--flush">
          @if ($pcSonuc)
            <div @class(['alert', 'alert--danger' => $pcSonuc['hata'], 'alert--success' => ! $pcSonuc['hata'], 'field--separated'])>
              <strong>{{ $pcSonuc['pc'] }}:</strong>
              @isset($pcSonuc['metin'])
                <pre class="kod">{{ $pcSonuc['metin'] }}</pre>
              @endisset
              @isset($pcSonuc['durum'])
                <div class="text-sm stack">
                  <div>Ajan: <strong>{{ $pcSonuc['durum']['gorev'] ?: '—' }}</strong> · sürüm {{ $pcSonuc['durum']['surum'] ?: '—' }}</div>
                  <div>Seri portlar:<pre class="kod">{{ implode("\n", $pcSonuc['durum']['portlar']) ?: 'seri port yok (konsol kablosu takılı mı?)' }}</pre></div>
                  <div>Ajan kaydı (son 40 satır):<pre class="kod">{{ implode("\n", $pcSonuc['durum']['kayit']) ?: 'kayıt yok' }}</pre></div>
                </div>
              @endisset
            </div>
          @endif

          <table class="table">
            <thead>
              <tr><th>PC</th><th>Bağlantı</th><th>Loader adresi</th><th>Ajan</th><th></th></tr>
            </thead>
            <tbody>
              @forelse ($pcler as $satir)
                <tr>
                  <td data-label="PC"><strong>{{ $satir['pc']->ad }}</strong><div class="text-sm text-muted">{{ $satir['platform'] }} · seri port {{ $satir['seriPort'] }}</div></td>
                  <td data-label="Bağlantı" class="table__cell--mono text-sm">{{ $satir['pc']->kullanici.'@'.$satir['pc']->adres.':'.$satir['pc']->port }}</td>
                  <td data-label="Loader adresi" class="table__cell--mono text-sm">{{ $satir['pc']->cihaz_ip }} · gw {{ $satir['pc']->gecit }}</td>
                  <td data-label="Ajan" class="text-sm">
                    <strong>{{ $satir['ajan'] }}</strong> · son bağlantı {{ $satir['ajanSon'] }}
                    @if ($satir['ajanMesaj'])
                      <div class="text-muted">{{ $satir['ajanMesaj'] }}</div>
                    @endif
                  </td>
                  <td>
                    <div class="table__row-actions">
                      @foreach (['sina' => 'Sına', 'durum' => 'Durum', 'kur' => 'Kur', 'kaldir' => 'Kaldır'] as $islem => $etiket)
                        <form method="POST" action="{{ route('konsol.pc.islem', [$satir['pc'], $islem]) }}">
                          @csrf
                          <button type="submit" class="btn btn--sm">{{ $etiket }}</button>
                        </form>
                      @endforeach
                      <button type="button" class="btn btn--sm" data-modal-open="konsol-pc-{{ $satir['pc']->id }}"><x-icon name="edit" />Düzenle</button>
                      <x-ui.delete-form :action="route('konsol.pc.destroy', $satir['pc'])" :confirm="$satir['silOnay']"
                                        title="PC'yi sil" :verify="$satir['pc']->ad" reasonless />
                    </div>
                  </td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-muted">PC yok. İş açmak için önce bir PC ekleyin.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </section>
    @endif

    {{-- Pencereler `.page__inner`in içinde: yenilemesiz geçiş yalnız bu öğeyi değiştiriyor. --}}
    @if ($yetki['ekle'] && $pcSecenekleri !== [])
      <x-ui.modal id="konsol-yeni" title="Yeni iş" :open="$ekleAcik" wide>
        <form method="POST" action="{{ route('konsol.store') }}">
          @csrf
          <input type="hidden" name="_form" value="ekle">
          <div class="modal__body stack">
            <x-konsol::is-alanlari :deger="$ekleDeger" :hatali="$ekleAcik" :pcler="$pcSecenekleri"
                                   :goruntuler="$goruntuSecenekleri" :yapilandirma-turleri="$yapilandirmaTurleri"
                                   :yedek-cihazlari="$yedekCihazlari" :yedek-dosyalari="$yedekDosyalari"
                                   :pc-adresleri="$pcAdresleri" :adres-turleri="$adresTurleri" />
          </div>
          <x-ui.modal-actions submit="İşi aç" />
        </form>
      </x-ui.modal>
    @endif

    @if ($yetki['pc'])
      <x-ui.modal id="konsol-pc-yeni" title="PC ekle" :open="$pcYeniAcik" wide>
        <form method="POST" action="{{ route('konsol.pc.store') }}">
          @csrf
          <input type="hidden" name="_form" value="pc-yeni">
          <div class="modal__body stack">
            <x-konsol::pc-alanlari onek="pc-yeni" :deger="$pcYeniDeger" :hatali="$pcYeniAcik" :platformlar="$platformlar" yeni />
          </div>
          <x-ui.modal-actions submit="Ekle" />
        </form>
      </x-ui.modal>

      @foreach ($pcler as $satir)
        <x-ui.modal id="konsol-pc-{{ $satir['pc']->id }}" :title="$satir['pc']->ad.' — düzenle'" :open="$satir['acik']" wide>
          <form method="POST" action="{{ route('konsol.pc.update', $satir['pc']) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="pc-{{ $satir['pc']->id }}">
            <div class="modal__body stack">
              <x-konsol::pc-alanlari :onek="'pc-'.$satir['pc']->id" :deger="$satir['deger']" :hatali="$satir['acik']" :platformlar="$platformlar" />
            </div>
            <x-ui.modal-actions submit="Güncelle" />
          </form>
        </x-ui.modal>
      @endforeach
    @endif
  </div>
</div>
</x-layout.app>
