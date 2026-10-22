@if ($goster)
    {{--
        YALNIZCA YÖNETİCİYE: sertifikayı yenileyecek kişi odur. Diğerleri
        için yapabilecekleri bir şey olmayan bir uyarı gürültüdür.

        Yenileme ARTIK PANELDEN yapılıyor; uyarı da oraya götürür. Sunucuya
        girmeyi anlatan bir cümle, düğmeye basmakla biten bir işi gereğinden
        zor gösterirdi.
    --}}
    <div class="alert alert--warning alert--spaced" role="alert">
        <strong>TLS sertifikasının süresi doluyor.</strong>
        {{ $durum['kalan_gun'] }} gün kaldı ({{ $durum['bitis'] }}).
        <a href="{{ route('admin.certificate.index') }}">Ayarlar → Sertifika</a>
        ekranından yeni tur başlatın: TXT kaydı orada görünür, DNS'e ekleyip
        onaylamanız yeter.
    </div>
@endif
