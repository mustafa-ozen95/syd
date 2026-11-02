@props(['deger', 'hatali' => false, 'pcler' => [], 'goruntuler' => [],
         'yedekCihazlari' => [], 'yedekDosyalari' => [],
         'pcAdresleri' => [], 'adresTurleri' => [], 'islemler' => []])

{{-- Yeni işin alanları. Değerler hazır gelir (ConsolePage); hata yalnız gönderilen pencerede. --}}
@if ($hatali)
    <x-ui.errors />
@endif

<div class="field">
    <label class="field__label" for="console-mode">İşlem</label>
    <select class="select" id="console-mode" name="islem" data-console-mode>
        @foreach ($islemler as $anahtar => $etiket)
            <option value="{{ $anahtar }}" @selected($deger['islem'] === $anahtar)>{{ $etiket }}</option>
        @endforeach
    </select>
    <span class="field__hint">"Yalnız yedeği yükle"de cihaz yeniden başlatılmaz, Junos kurulmaz: ajan CLI'ye girer ve yedeği yükler.</span>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="console-pc">PC</label>
        <select class="select" id="console-pc" name="pc" required data-console-pc>
            @foreach ($pcler as $uuid => $ad)
                <option value="{{ $uuid }}" data-ip="{{ $pcAdresleri[$uuid]['ip'] ?? '' }}" data-gateway="{{ $pcAdresleri[$uuid]['gecit'] ?? '' }}"
                        @selected($deger['pc'] === $uuid)>{{ $ad }}</option>
            @endforeach
        </select>
        <span class="field__hint">Switch'in konsol kablosu bu PC'ye takılı olmalı.</span>
    </div>
    <div class="field">
        <label class="field__label" for="console-name">Cihaz</label>
        <select class="select" id="console-name" name="ad" data-console-device data-url="{{ route('console.backups') }}">
            <option value="" @selected($deger['ad'] === '')>Listede yok (fabrika ayarında kur)</option>
            @foreach ($yedekCihazlari as $cihaz)
                <option value="{{ $cihaz }}" @selected($deger['ad'] === $cihaz)>{{ $cihaz }}</option>
            @endforeach
        </select>
        <span class="field__hint">Listeden seçilen cihazın yedeği kurulumdan sonra yüklenir; listede yoksa fabrika ayarında kalır.</span>
    </div>
</div>

{{-- Kurulumun alanları (adres, görüntü): "Yalnız yedek"te gizli; console-form.js açar/kapar. --}}
<div @class(['stack', 'is-hidden' => $deger['islem'] === 'yedek']) data-console-install>
{{-- Switch'in loader'daki adresi; PC kaydındakiyle dolu gelir. DHCP'de adres satırları gönderilmez. --}}
<div class="field-row">
    <div class="field">
        <label class="field__label" for="console-address-type">Switch'in adresi (loader)</label>
        <select class="select" id="console-address-type" name="adres_turu" data-console-address>
            @foreach ($adresTurleri as $anahtar => $etiket)
                <option value="{{ $anahtar }}" @selected($deger['adres_turu'] === $anahtar)>{{ $etiket }}</option>
            @endforeach
        </select>
    </div>
    <div @class(['field', 'is-hidden' => $deger['adres_turu'] === 'dhcp']) data-console-fixed>
        <label class="field__label" for="console-device-ip">Adres / önek</label>
        <input class="input input--mono" type="text" id="console-device-ip" name="cihaz_ip" value="{{ $deger['cihaz_ip'] }}"
               maxlength="18" placeholder="198.51.100.120/24" data-console-ip>
    </div>
    <div @class(['field', 'is-hidden' => $deger['adres_turu'] === 'dhcp']) data-console-fixed>
        <label class="field__label" for="console-gateway">Ağ geçidi</label>
        <input class="input input--mono" type="text" id="console-gateway" name="gecit" value="{{ $deger['gecit'] }}"
               maxlength="45" placeholder="198.51.100.1" data-console-gateway>
    </div>
</div>
<span class="field__hint">Sabit adreste ajan önce adrese ping atar; başka bir cihaz cevap verirse kurmaz.</span>

<div class="field">
    <label class="field__label" for="console-image">Junos görüntüsü</label>
    <select class="select" id="console-image" name="goruntu" @required($deger['islem'] !== 'yedek') data-console-image>
        <option value="" @selected($deger['goruntu'] === '')>—</option>
        @foreach ($goruntuler as $g)
            <option value="{{ $g['anahtar'] }}" @selected($deger['goruntu'] === $g['anahtar'])>{{ $g['etiket'] }} · {{ $g['dosya'] }}</option>
        @endforeach
    </select>
    <span class="field__hint">Liste Kurulum sayfasındaki tablodur. <code>--format</code> ile kurulur: cihazdaki yapılandırma silinir.</span>
</div>
</div>

{{-- Yalnız listeden bir cihaz seçilince görünür; console-form.js açar/kapar, o cihazın yedeklerini çeker. --}}
<div @class(['field', 'is-hidden' => $deger['ad'] === '']) data-console-backup>
    <label class="field__label" for="console-backup-file">Yüklenecek yedek</label>
    <select class="select" id="console-backup-file" name="yedek_dosya" data-console-backup-file>
        @foreach ($yedekDosyalari as $y)
            <option value="{{ $y['dosya'] }}" @selected($deger['yedek_dosya'] === $y['dosya'])>{{ $y['etiket'] }}</option>
        @endforeach
    </select>
    <span class="field__hint">En yenisi en üstte. Yükleme <code>load override terminal</code> + commit ile yapılır.</span>
</div>

<div class="field">
    <label class="field__label" for="console-root">Cihazın şu anki root parolası (isteğe bağlı)</label>
    <input class="input" type="password" id="console-root" name="root_parola" maxlength="128" autocomplete="new-password">
    <span class="field__hint">
        Kurulumda cihaz çalışıyorsa ajan girip <code>request system reboot</code> ile loader'a iner; parola yoksa ya da tutmazsa
        sizden cihazın elektriğini çekip takmanızı ister. Yalnız yedekte ajan bu parolayla girer (sıfır cihazda boş bırakın).
        Şifreli saklanır, iş bitince silinir.
    </span>
</div>
