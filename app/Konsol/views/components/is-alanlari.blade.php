@props(['deger', 'hatali' => false, 'pcler' => [], 'goruntuler' => [],
         'yapilandirmaTurleri' => [], 'yedekCihazlari' => [], 'yedekDosyalari' => [],
         'pcAdresleri' => [], 'adresTurleri' => []])

{{-- Yeni işin alanları. Değerler hazır gelir (KonsolSayfasi); hata yalnız gönderilen pencerede. --}}
@if ($hatali)
    <x-ui.errors />
@endif

<div class="field-row">
    <div class="field">
        <label class="field__label" for="konsol-pc">PC</label>
        <select class="select" id="konsol-pc" name="pc_id" required data-konsol-pc>
            @foreach ($pcler as $id => $ad)
                <option value="{{ $id }}" data-ip="{{ $pcAdresleri[$id]['ip'] ?? '' }}" data-gecit="{{ $pcAdresleri[$id]['gecit'] ?? '' }}"
                        @selected($deger['pc_id'] === (string) $id)>{{ $ad }}</option>
            @endforeach
        </select>
        <span class="field__hint">Switch'in konsol kablosu bu PC'ye takılı olmalı.</span>
    </div>
    <div class="field">
        <label class="field__label" for="konsol-ad">Cihaz</label>
        <select class="select" id="konsol-ad" name="ad" data-konsol-cihaz>
            <option value="" @selected($deger['ad'] === '')>Listede yok (yeni cihaz)</option>
            @foreach ($yedekCihazlari as $cihaz)
                <option value="{{ $cihaz }}" @selected($deger['ad'] === $cihaz)>{{ $cihaz }}</option>
            @endforeach
        </select>
        <span class="field__hint">"Cihazın en son yedeği" bu cihazın yedek klasöründen yüklenir.</span>
    </div>
</div>

{{-- Switch'in loader'daki adresi; PC kaydındakiyle dolu gelir. DHCP'de adres satırları gönderilmez. --}}
<div class="field-row">
    <div class="field">
        <label class="field__label" for="konsol-adres-turu">Switch'in adresi (loader)</label>
        <select class="select" id="konsol-adres-turu" name="adres_turu" data-konsol-adres>
            @foreach ($adresTurleri as $anahtar => $etiket)
                <option value="{{ $anahtar }}" @selected($deger['adres_turu'] === $anahtar)>{{ $etiket }}</option>
            @endforeach
        </select>
    </div>
    <div @class(['field', 'is-hidden' => $deger['adres_turu'] === 'dhcp']) data-konsol-sabit>
        <label class="field__label" for="konsol-cihaz-ip">Adres / önek</label>
        <input class="input input--mono" type="text" id="konsol-cihaz-ip" name="cihaz_ip" value="{{ $deger['cihaz_ip'] }}"
               maxlength="18" placeholder="198.51.100.120/24" data-konsol-ip>
    </div>
    <div @class(['field', 'is-hidden' => $deger['adres_turu'] === 'dhcp']) data-konsol-sabit>
        <label class="field__label" for="konsol-gecit">Ağ geçidi</label>
        <input class="input input--mono" type="text" id="konsol-gecit" name="gecit" value="{{ $deger['gecit'] }}"
               maxlength="45" placeholder="198.51.100.1" data-konsol-gecit>
    </div>
</div>
<span class="field__hint">Sabit adreste ajan önce adrese ping atar; başka bir cihaz cevap verirse kurmaz.</span>

<div class="field">
    <label class="field__label" for="konsol-goruntu">Junos görüntüsü</label>
    <select class="select" id="konsol-goruntu" name="goruntu" required>
        <option value="" @selected($deger['goruntu'] === '')>—</option>
        @foreach ($goruntuler as $g)
            <option value="{{ $g['anahtar'] }}" @selected($deger['goruntu'] === $g['anahtar'])>{{ $g['etiket'] }} · {{ $g['dosya'] }}</option>
        @endforeach
    </select>
    <span class="field__hint">Liste Kurulum sayfasındaki tablodur. <code>--format</code> ile kurulur: cihazdaki yapılandırma silinir.</span>
</div>

{{-- "Seçilen yedek" dışında cihaz ve dosya gizli; değişince konsol-form.js açar/kapar, dosyaları çeker. --}}
<div class="field-row">
    <div class="field">
        <label class="field__label" for="konsol-yapilandirma">Kurulumdan sonra</label>
        <select class="select" id="konsol-yapilandirma" name="yapilandirma_turu" data-konsol-yapilandirma>
            @foreach ($yapilandirmaTurleri as $anahtar => $etiket)
                <option value="{{ $anahtar }}" @selected($deger['yapilandirma_turu'] === $anahtar)>{{ $etiket }}</option>
            @endforeach
        </select>
        <span class="field__hint">Yükleme <code>load override terminal</code> + commit ile yapılır.</span>
    </div>
    <div @class(['field', 'is-hidden' => $deger['yapilandirma_turu'] !== 'yedek']) data-konsol-sadece="yedek">
        <label class="field__label" for="konsol-yedek-cihaz">Yedeği yüklenecek cihaz</label>
        <select class="select" id="konsol-yedek-cihaz" name="yedek_cihaz" data-konsol-yedek-cihaz data-adres="{{ route('konsol.yedekler') }}">
            <option value="" @selected($deger['yedek_cihaz'] === '')>—</option>
            @foreach ($yedekCihazlari as $cihaz)
                <option value="{{ $cihaz }}" @selected($deger['yedek_cihaz'] === $cihaz)>{{ $cihaz }}</option>
            @endforeach
        </select>
    </div>
</div>

<div @class(['field', 'is-hidden' => $deger['yapilandirma_turu'] !== 'yedek']) data-konsol-sadece="yedek">
    <label class="field__label" for="konsol-yedek-dosya">Yedek</label>
    <select class="select" id="konsol-yedek-dosya" name="yedek_dosya" data-konsol-yedek-dosya>
        <option value="">{{ $yedekDosyalari === [] ? 'Önce cihazı seçin' : '— seçin —' }}</option>
        @foreach ($yedekDosyalari as $y)
            <option value="{{ $y['dosya'] }}" @selected($deger['yedek_dosya'] === $y['dosya'])>{{ $y['etiket'] }}</option>
        @endforeach
    </select>
    <span class="field__hint">En yenisi en üstte.</span>
</div>

<div class="field">
    <label class="field__label" for="konsol-root">Cihazın şu anki root parolası (isteğe bağlı)</label>
    <input class="input" type="password" id="konsol-root" name="root_parola" maxlength="128" autocomplete="new-password">
    <span class="field__hint">
        Cihaz çalışıyorsa ajan girip <code>request system reboot</code> ile loader'a iner. Parola yoksa ya da tutmazsa
        sizden cihazın elektriğini çekip takmanızı ister. Şifreli saklanır, iş bitince silinir.
    </span>
</div>
