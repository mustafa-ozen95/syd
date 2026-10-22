@props(['onek', 'deger' => [], 'hatali' => false, 'tftp' => '', 'sinir' => '', 'duzenle' => false])

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
    <label class="field__label" for="{{ $onek }}-goruntu">Görüntü dosyası</label>
    <input class="input" type="file" id="{{ $onek }}-goruntu" name="goruntu">
    <span class="field__hint">
        @if ($duzenle)
            Boş bırakılırsa TFTP'deki dosya kalır. Seçilirse onun yerine geçer.
        @else
            İsteğe bağlı; seçilmezse dosyanın TFTP dizinine başka yoldan konması gerekir.
        @endif
        En çok {{ $sinir }}. Büyük dosyada yükleme dakikalar sürebilir, sayfayı kapatmayın.
    </span>
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-dosya">Dosya adı</label>
    <input class="input input--mono" type="text" id="{{ $onek }}-dosya" name="dosya" value="{{ $deger['dosya'] ?? '' }}"
           @if ($duzenle) required @endif maxlength="200" placeholder="jinstall-ex-4300-21.4R3.15-signed.tgz">
    <span class="field__hint">
        TFTP sunucusundaki adı; komut {{ $tftp }}/&lt;dosya&gt; olarak kurulur.
        @if ($duzenle)
            Değiştirilirse TFTP'deki dosyanın adı da değişir.
        @else
            Boş bırakılırsa yüklenen dosyanın adı alınır.
        @endif
    </span>
</div>

<div class="field">
    <label class="field__label" for="{{ $onek }}-sira">Sıra</label>
    <input class="input" type="number" id="{{ $onek }}-sira" name="sira" value="{{ $deger['sira'] ?? '' }}" min="0" max="9999">
    <span class="field__hint">Küçük olan üstte. Yeni görüntüde boş bırakılırsa listenin sonuna eklenir.</span>
</div>
