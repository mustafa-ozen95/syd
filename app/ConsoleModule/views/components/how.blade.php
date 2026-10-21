@props(['acik' => false, 'loaderKomutlari' => [], 'kurulumKomutu' => '', 'tftp' => ''])

{{-- Kullanım notu. Düz metin; hesap yok. --}}
<details class="panel" @if ($acik) open @endif>
  <summary class="panel__head"><h2 class="panel__title">Nasıl yapılır?</h2></summary>
  <div class="panel__body stack text-sm">
    <p>
      Elle yaptığınızın aynısı, kendiliğinden: PC'deki <strong>ajan</strong> konsol kablosundan switch'i loader'a indirir,
      adres verir, görüntüyü TFTP'den kurar, sürümü doğrular ve istenmişse cihazın yedeğini yükler. Konsolda görülen her şey
      işin sayfasında canlı akar; takılırsa oradan konsola elle yazabilirsiniz.
    </p>

    <h3>Bir kez yapılacak hazırlık</h3>
    <ol class="stack">
      <li><strong>PC'yi ekleyin</strong> (aşağıda "PC ekle"): WinRM (Windows) ya da SSH (Linux) bilgisi ve switch'e loader'da
          verilecek boş bir adres (ör. <code>198.51.100.120/24</code>, ağ geçidi <code>198.51.100.1</code>). "Sına" ile bağlantıyı deneyin.</li>
      <li>Ajanı elle kurmanız gerekmez: iş açılınca panel kurar, iş bitince kaldırır.</li>
    </ol>

    <h3>Her kurulumda</h3>
    <ol class="stack">
      <li><strong>İki kablo:</strong> konsol kablosu (USB-seri dönüştürücüyle) PC'ye, switch'in <strong>mgmt (me0)</strong> portu
          198.51.100.x ağına. Görüntü {{ $tftp }} üzerinden iner (TFTP sunucusunun görüntü dizininden); işin sayfası kaç MB indiğini gösterir.</li>
      <li>"Yeni iş" → PC, cihaz, görüntü, kurulumdan sonra yüklenecek yedek. Cihaz çalışıyorsa ve şifreliyse root parolasını
          da verin; ajan girip yeniden başlatır.</li>
      <li>İşin sayfası açılır. Ajan bir dakika içinde başlar. Cihaz cevap vermiyorsa ya da parola tutmazsa ekranda
          <strong>"Sizden bekleniyor"</strong> çıkar: cihazın elektriğini çekip takın, gerisi kendiliğinden.</li>
      <li>Kurulum modele göre 15 dakikadan birkaç saate kadar sürer. Bitince durum <strong>Tamam</strong> olur ve PC'deki ajan
          kaldırılır.</li>
    </ol>

    <h3>Ajanın konsolda yazdıkları</h3>
    <ul class="stack">
      <li>Çalışan cihazda: <code>root</code> + parola, <code>cli</code>, <code>request system reboot</code>, <code>yes</code>.</li>
      <li>Açılışta art arda boşluk (U-Boot'ta durursa <code>boot</code>; EX2300'de "press Ctrl-C" görülünce Ctrl-C ve menüden
        "[B]oot prompt"), <code>loader&gt;</code> isteminde:
        @foreach ($loaderKomutlari as $satir)
          <code>{{ $satir }}</code>@if (! $loop->last), @endif
        @endforeach
        ve <code>{{ $kurulumKomutu }}</code>.</li>
      <li>Açılınca: <code>root</code>, <code>cli</code>, <code>show version | no-more</code> (sürüm doğrulanır); yedek seçildiyse
          <code>configure</code>, <code>load override terminal</code>, yedek, Ctrl+D, <code>commit and-quit</code>.</li>
    </ul>

    <p class="text-muted">
      Hız otomatik bulunur (önce 115200, sonra 9600). EX2300 ve QFX5100'de açılış menüsü çıkarsa ajan sizi uyarır; menüden loader'a
      konsoldan elle inebilirsiniz. Komutlar <code>app/ConsoleModule/config.php</code> içindedir.
    </p>
  </div>
</details>
