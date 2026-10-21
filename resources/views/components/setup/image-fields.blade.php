@props(['onek', 'deger' => [], 'hatali' => false, 'tftp' => ''])

{{--
    Junos görüntüsü pencere alanları.

    x-form.input KULLANILMAZ: o bileşen değeri her zaman old($name)'den
    okuyor ve sayfadaki bütün pencerelerde alan adları aynı — hatalı bir
    gönderimin girdisi her pencereye yayılırdı. Değer hazır gelir
    (SetupController), hata yalnız gönderilen pencerede görünür.
--}}
@if ($hatali)
    <x-ui.errors />
@endif

<div class="field">
    <label class="field__label" for="{{ $onek }}-model">Model</label>
    <input class="input" type="text" id="{{ $onek }}-model" name="model" value="{{ $deger['model'] ?? '' }}"
           required maxlength="60" placeholder="EX-4300">
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-surum">Sürüm</label>
    <input class="input" type="text" id="{{ $onek }}-surum" name="surum" value="{{ $deger['surum'] ?? '' }}"
           required maxlength="40" placeholder="21.4">
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-dosya">Dosya adı</label>
    <input class="input input--mono" type="text" id="{{ $onek }}-dosya" name="dosya" value="{{ $deger['dosya'] ?? '' }}"
           required maxlength="200" placeholder="jinstall-ex-4300-21.4R3.15-signed.tgz">
    <span class="field__hint">TFTP sunucusundaki adıyla; komut {{ $tftp }}/&lt;dosya&gt; olarak kurulur. Panel dosya yüklemez.</span>
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-sira">Sıra</label>
    <input class="input" type="number" id="{{ $onek }}-sira" name="sira" value="{{ $deger['sira'] ?? '' }}" min="0" max="9999">
    <span class="field__hint">Küçük olan üstte. Yeni görüntüde boş bırakılırsa listenin sonuna eklenir.</span>
</div>
