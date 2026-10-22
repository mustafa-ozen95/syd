@props(['onek', 'deger', 'hatali' => false, 'platformlar' => [], 'yeni' => false])

{{-- Konsol PC'si alanları. Değerler hazır gelir (ConsolePage); parola hiçbir zaman geri gösterilmez. --}}
@if ($hatali)
    <x-ui.errors />
@endif

<div class="field-row">
    <div class="field">
        <label class="field__label" for="{{ $onek }}-ad">Ad</label>
        <input class="input" type="text" id="{{ $onek }}-ad" name="ad" value="{{ $deger['ad'] }}" required maxlength="60" placeholder="PC-01">
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-platform">Platform</label>
        <select class="select" id="{{ $onek }}-platform" name="platform">
            @foreach ($platformlar as $anahtar => $etiket)
                <option value="{{ $anahtar }}" @selected($deger['platform'] === $anahtar)>{{ $etiket }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="{{ $onek }}-adres">Adres</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-adres" name="adres" value="{{ $deger['adres'] }}" required maxlength="120" placeholder="198.51.100.16">
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-gorunen">Panelin gördüğü adres</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-gorunen" name="gorunen_adres" value="{{ $deger['gorunen_adres'] }}" maxlength="45" placeholder="NAT varsa: 192.0.2.1">
        <span class="field__hint">PC panele NAT arkasından ulaşıyorsa (198.51.100.x → 192.0.2.1). Ajan jetonu yalnız bu iki adresten kabul edilir.</span>
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-port">Port</label>
        <input class="input input--mono" type="number" id="{{ $onek }}-port" name="port" value="{{ $deger['port'] }}" min="1" max="65535" placeholder="Windows 5985 · Linux 22">
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="{{ $onek }}-kullanici">Kullanıcı</label>
        <input class="input" type="text" id="{{ $onek }}-kullanici" name="kullanici" value="{{ $deger['kullanici'] }}" required maxlength="64" autocomplete="off">
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-parola">Parola</label>
        <input class="input" type="password" id="{{ $onek }}-parola" name="parola" @if ($yeni) required @endif maxlength="256"
               autocomplete="new-password" placeholder="{{ $yeni ? '' : 'değişmesin diye boş bırakın' }}">
    </div>
</div>
<span class="field__hint">
    Hesap PC'de yönetici olmalı (Linux'ta root). Parola şifreli saklanır ve geri gösterilmez.
    Windows'ta WinRM açık olmalı (Enable-PSRemoting), Linux'ta SSH.
</span>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="{{ $onek }}-cihaz-ip">Switch'e loader'da verilecek adres</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-cihaz-ip" name="cihaz_ip" value="{{ $deger['cihaz_ip'] }}" required maxlength="18" placeholder="198.51.100.120/24">
        <span class="field__hint">Switch'in mgmt (me0) portu bu ağa takılır, görüntüyü TFTP sunucusundan bu adresle çeker. Ağda boş bir adres olmalı; ajan kurmadan önce ping'le bakar.</span>
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-gecit">Ağ geçidi</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-gecit" name="gecit" value="{{ $deger['gecit'] }}" required maxlength="15" placeholder="198.51.100.1">
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="{{ $onek }}-seri">Seri port</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-seri" name="seri_port" value="{{ $deger['seri_port'] }}" maxlength="60" placeholder="boş: otomatik · COM3 · /dev/ttyUSB0">
        <span class="field__hint">Boşsa ajan USB-seri dönüştürücüyü kendisi bulur; birden çoksa cihazın cevap verdiğini seçer. Hız da otomatik (önce 115200, sonra 9600).</span>
    </div>
    <div class="field">
        <label class="field__label" for="{{ $onek }}-dizin">Ajan dizini</label>
        <input class="input input--mono" type="text" id="{{ $onek }}-dizin" name="dizin" value="{{ $deger['dizin'] }}" maxlength="120" placeholder="C:\Console-Agent · /opt/console-agent">
    </div>
</div>
