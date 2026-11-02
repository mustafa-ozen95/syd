@props(['deger', 'hatali' => false, 'pcler' => [], 'goruntuler' => [],
         'yedekCihazlari' => [], 'yedekDosyalari' => [], 'portSecenekleri' => [], 'sablonlar' => [], 'ozellestirTumu' => false, 'ozGorunur' => false,
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
    <span class="field__hint">"Yalnız yedeği yükle"de cihaz yeniden başlatılmaz, Junos kurulmaz: ajan CLI'ye girer ve yedeği yükler. "CLI'den kur" loader'ı olmayan QFX5100, QFX5110 ve EX4600 içindir: Junos çalışan cihazda <code>request system software add</code> ile kurulur, görüntü panelin HTTP'sinden çekilir.</span>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="console-pc">PC</label>
        <select class="select" id="console-pc" name="pc" required data-console-pc>
            @foreach ($pcler as $uuid => $ad)
                <option value="{{ $uuid }}" data-ip="{{ $pcAdresleri[$uuid]['ip'] ?? '' }}" data-gateway="{{ $pcAdresleri[$uuid]['gecit'] ?? '' }}"
                        data-ports="{{ json_encode($pcAdresleri[$uuid]['portlar'] ?? []) }}"
                        @selected($deger['pc'] === $uuid)>{{ $ad }}</option>
            @endforeach
        </select>
        <span class="field__hint">Switch'in konsol kablosu bu PC'ye takılı olmalı.</span>
    </div>
    {{-- Seçenekler seçili PC'nin portları; PC değişince console-form.js yeniden doldurur. Birden çok portta seçmek şart. --}}
    <div class="field">
        <label class="field__label" for="console-port">Seri port</label>
        <select class="select" id="console-port" name="seri_port" data-console-port>
            @foreach ($portSecenekleri as $portDeger => $etiket)
                <option value="{{ $portDeger }}" @selected($deger['seri_port'] === $portDeger)>{{ $etiket }}</option>
            @endforeach
        </select>
        <span class="field__hint">Her portta aynı anda bir iş: birden çok kablo takılıysa her biri ayrı switch kurar.</span>
    </div>
    <div class="field">
        <label class="field__label" for="console-name">Cihaz</label>
        <select class="select" id="console-name" name="ad" data-console-device data-url="{{ route('console.backups') }}">
            <option value="" @selected($deger['ad'] === '')>Listede yok (fabrika ayarında kur)</option>
            @foreach ($yedekCihazlari as $cihaz)
                <option value="{{ $cihaz }}" @selected($deger['ad'] === $cihaz) @isset($sablonlar[$cihaz]) data-sablon @endisset>{{ $cihaz }}</option>
            @endforeach
        </select>
        <span class="field__hint">Listeden seçilen cihazın yedeği kurulumdan sonra yüklenir; listede yoksa fabrika ayarında kalır.</span>
    </div>
</div>

<div class="field">
    <label class="field__label" for="console-backup-upload">ya da bilgisayardan yedek dosyası (isteğe bağlı)</label>
    <input class="input" type="file" id="console-backup-upload" name="yedek_yukle" accept=".conf,.cfg,.txt,.gz" data-console-backup-upload>
    <span class="field__hint">Seçilirse listedeki cihazın yerine bu dosya yüklenir (düz metin ya da .gz, en çok 8 MB). Yalnız bu işte kullanılır, iş bitince silinir.</span>
</div>

{{-- Kurulumun alanları (adres, görüntü): "Yalnız yedek"te gizli; console-form.js açar/kapar. --}}
<div @class(['stack', 'is-hidden' => $deger['islem'] === 'yedek']) data-console-install>
{{-- Switch'in loader'daki adresi; PC kaydındakiyle dolu gelir. DHCP'de adres satırları gönderilmez. --}}
<div @class(['stack', 'is-hidden' => $deger['islem'] === 'cli']) data-console-loader>
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
<span class="field__hint">Sabit adreste ajan önce adrese ping atar; başka bir cihaz cevap verirse kurmaz. Aynı PC'de aynı anda kurulan switch'ler aynı loader adresini alamaz: ikinci işe başka bir adres verin (panel aynı adresi reddeder).</span>
</div>

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
<div class="field">
    <label class="field__label" for="console-image-upload">ya da bilgisayardan Junos dosyası (isteğe bağlı)</label>
    <input class="input" type="file" id="console-image-upload" name="goruntu_yukle" accept=".tgz" data-console-image-upload>
    <span class="field__hint">Seçilirse listedeki görüntünün yerine bu dosya kurulur (.tgz). Yalnız bu iş için TFTP'ye konur, iş bitince silinir. Büyük dosyada "İşi aç" yükleme bitene kadar sürer.</span>
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

{{--
    Yapılandırmayı özelleştir: yalnız listeden cihaz seçiliyken. Alanlar seçilen yedekteki değerlerle
    dolar (console-form.js, console.fields); değiştirilmeyen alana dokunulmaz. Önizleme ajanın yazacağı
    satırların aynısıdır (console.preview).
--}}
{{-- Config şablonunda herkes; cihaz yedeğinde ve yüklenen dosyada yalnız "Yedekte özelleştirme" yetkisi olan görür. --}}
<div @class(['stack', 'is-hidden' => ! $ozGorunur]) data-console-custom-wrap data-serbest="{{ $ozellestirTumu ? '1' : '0' }}">
    <label class="checkbox">
        <input type="checkbox" name="ozellestir" value="1" @checked($deger['ozellestir'] === '1') data-console-custom-toggle>
        <span>Yapılandırmayı özelleştir (isteğe bağlı)</span>
    </label>
    <div @class(['stack', 'is-hidden' => $deger['ozellestir'] !== '1']) data-console-custom
         data-fields-url="{{ route('console.fields') }}" data-preview-url="{{ route('console.preview') }}">
        <span class="field__hint">Alanlar seçilen yedekteki değerlerle dolar. Değiştirmediğiniz alana dokunulmaz; değişiklikler yedekle aynı commit'te uygulanır, hata olursa ikisi birden geri alınır.</span>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="oz-host">Makine adı (host-name)</label>
                <input class="input input--mono" type="text" id="oz-host" name="ozellestirme[host_name]" value="{{ $deger['oz']['host_name'] ?? '' }}" maxlength="63" data-oz="host_name">
            </div>
            <div class="field">
                <label class="field__label" for="oz-me0">me0 (yönetim) adresi</label>
                <input class="input input--mono" type="text" id="oz-me0" name="ozellestirme[me0_adres]" value="{{ $deger['oz']['me0_adres'] ?? '' }}" maxlength="18" placeholder="198.51.100.16/24" data-oz="me0_adres">
                <label class="checkbox"><input type="checkbox" name="ozellestirme[me0_kaldir]" value="1" @checked(! empty($deger['oz']['me0_kaldir']))><span>me0 adresini kaldır</span></label>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="oz-uplink">Uplink arayüzü</label>
                <input class="input input--mono" type="text" id="oz-uplink" name="ozellestirme[uplink_arayuz]" value="{{ $deger['oz']['uplink_arayuz'] ?? '' }}" maxlength="12" placeholder="xe-0/1/0" data-oz="uplink_arayuz">
            </div>
            <div class="field">
                <label class="field__label" for="oz-uplink-ip">Uplink adresi / önek</label>
                <input class="input input--mono" type="text" id="oz-uplink-ip" name="ozellestirme[uplink_adres]" value="{{ $deger['oz']['uplink_adres'] ?? '' }}" maxlength="18" placeholder="10.0.0.230/30" data-oz="uplink_adres">
            </div>
            <div class="field">
                <label class="field__label" for="oz-uplink-gw">Varsayılan geçit</label>
                <input class="input input--mono" type="text" id="oz-uplink-gw" name="ozellestirme[uplink_gecit]" value="{{ $deger['oz']['uplink_gecit'] ?? '' }}" maxlength="15" placeholder="10.0.0.229" data-oz="uplink_gecit">
            </div>
        </div>
        <span class="field__hint">Uplink değişirse eski arayüz sıradan switch portuna döner; yeni arayüz VLAN'dan çıkarılır ve eski arayüzdeki çıkış filtresini (koruma) alır. Arayüz cihazda yoksa ajan commit sonrası uyarır.</span>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="oz-vlan0">vlan.0 adresleri (müşteri geçitleri)</label>
                <textarea class="textarea input--mono" id="oz-vlan0" name="ozellestirme[vlan0_adresleri]" rows="3" data-oz="vlan0_adresleri">{{ $deger['oz']['vlan0_adresleri'] ?? '' }}</textarea>
            </div>
            <div class="field">
                <label class="field__label" for="oz-koru">koru (izin verilen kaynak bloklar)</label>
                <textarea class="textarea input--mono" id="oz-koru" name="ozellestirme[koru]" rows="3" data-oz="koru">{{ $deger['oz']['koru'] ?? '' }}</textarea>
            </div>
        </div>

        <div class="field-row">
            <div class="field" data-oz-field="re_filter">
                <label class="field__label" for="oz-re">RE_FILTER whitelist (switch'e erişim)</label>
                <textarea class="textarea input--mono" id="oz-re" name="ozellestirme[re_filter]" rows="3" data-oz="re_filter">{{ $deger['oz']['re_filter'] ?? '' }}</textarea>
            </div>
            <div class="field" data-oz-field="me_filter">
                <label class="field__label" for="oz-me">ME_FILTER whitelist (me0'a erişim)</label>
                <textarea class="textarea input--mono" id="oz-me" name="ozellestirme[me_filter]" rows="3" data-oz="me_filter">{{ $deger['oz']['me_filter'] ?? '' }}</textarea>
            </div>
        </div>
        <span class="field__hint">Listeler satır başına bir adres ya da blok. Boş bırakılan listeye dokunulmaz. Whitelist'e kendi erişim adresinizi eklemeyi unutmayın.</span>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="oz-snmp-ad">SNMP adı</label>
                <input class="input" type="text" id="oz-snmp-ad" name="ozellestirme[snmp_ad]" value="{{ $deger['oz']['snmp_ad'] ?? '' }}" maxlength="120" data-oz="snmp_ad">
            </div>
            <div class="field">
                <label class="field__label" for="oz-snmp-aciklama">SNMP açıklaması</label>
                <input class="input" type="text" id="oz-snmp-aciklama" name="ozellestirme[snmp_aciklama]" value="{{ $deger['oz']['snmp_aciklama'] ?? '' }}" maxlength="120" data-oz="snmp_aciklama">
            </div>
            <div class="field">
                <label class="field__label" for="oz-snmp-c">SNMP community'leri</label>
                <textarea class="textarea input--mono" id="oz-snmp-c" name="ozellestirme[snmp_communityler]" rows="2" data-oz="snmp_communityler">{{ $deger['oz']['snmp_communityler'] ?? '' }}</textarea>
            </div>
        </div>
        <div class="row row--wrap">
            <label class="checkbox"><input type="checkbox" name="ozellestirme[snmp_engine_kaldir]" value="1" @checked(! empty($deger['oz']['snmp_engine_kaldir']))><span>SNMP engine-id'yi kaldır</span></label>
            <label class="checkbox"><input type="checkbox" name="ozellestirme[archival_kaldir]" value="1" @checked(! empty($deger['oz']['archival_kaldir']))><span>Yedekleme hedefini (archival) kaldır</span></label>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="oz-root">Yeni root parolası</label>
                <input class="input" type="password" id="oz-root" name="ozellestirme[root_parola]" maxlength="128" autocomplete="new-password">
            </div>
            <div class="field">
                <label class="field__label" for="oz-admin">Yeni admin parolası</label>
                <input class="input" type="password" id="oz-admin" name="ozellestirme[admin_parola]" maxlength="128" autocomplete="new-password">
            </div>
        </div>
        <span class="field__hint">Boş bırakılırsa cihazın parolaları YEDEKTEKİLER olur. Yedeğin parolasını bilmiyorsanız burada belirleyin. Parola saklanmaz; cihaza yalnız özeti ($1$) gider, iş bitince o da silinir.</span>

        <div class="field">
            <label class="field__label" for="oz-ek">Ek set komutları (isteğe bağlı)</label>
            <textarea class="textarea input--mono" id="oz-ek" name="ozellestirme[ek_komutlar]" rows="3" placeholder="set system ntp server 198.51.100.10">{{ $deger['oz']['ek_komutlar'] ?? '' }}</textarea>
            <span class="field__hint">Satır başına bir set / delete komutu, en çok 50. Yanlış bir komut commit'i durdurur; yedek de geri alınır.</span>
        </div>

        <div class="stack">
            <div><button type="button" class="btn btn--sm" data-console-preview>Önizle</button></div>
            <pre class="code is-hidden" data-console-preview-out></pre>
        </div>
    </div>
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
