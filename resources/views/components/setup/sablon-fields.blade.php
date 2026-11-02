@props(['onek', 'deger' => [], 'hatali' => false, 'duzenle' => false])

{{-- Config şablonu pencere alanları. Değer hazır gelir (SetupController); hata yalnız gönderilen pencerede. --}}
@if ($hatali)
    <x-ui.errors />
@endif

<div class="field">
    <label class="field__label" for="{{ $onek }}-ad">Ad</label>
    <input class="input" type="text" id="{{ $onek }}-ad" name="ad" value="{{ $deger['ad'] ?? '' }}"
           required maxlength="120" placeholder="Sıfır Config (Default Vlan)">
    <span class="field__hint">Konsol'da yeni iş açarken cihaz listesinin en üstünde bu adla görünür.</span>
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-dosya">Yapılandırma dosyası</label>
    <input class="input" type="file" id="{{ $onek }}-dosya" name="dosya" accept=".conf,.cfg,.txt,.gz" @if (! $duzenle) required @endif>
    <span class="field__hint">
        Junos yapılandırması (show configuration çıktısı), düz metin ya da .gz, en çok 8 MB.
        @if ($duzenle)
            Boş bırakılırsa mevcut dosya kalır.
        @endif
    </span>
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-sira">Sıra</label>
    <input class="input" type="number" id="{{ $onek }}-sira" name="sira" value="{{ $deger['sira'] ?? '' }}" min="0" max="9999">
    <span class="field__hint">Küçük olan üstte. Yeni şablonda boş bırakılırsa listenin sonuna eklenir.</span>
</div>
