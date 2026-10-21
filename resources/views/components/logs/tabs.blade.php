@props(['aktif' => 'denetim'])

{{--
    Kayıt ekranlarının şeridi.

    İkisi AYRI SORULARDIR ve ayrı ekranlardır: denetim kaydı "kim ne yaptı",
    hata kaydı "ne patladı". Ama ikisi de aynı kaynaktan — panelde biriken
    kayıtlardan — okunuyor; sayfa başlığına düğme koymak onları ilgisiz
    sayfalar gibi gösteriyordu.
--}}
<x-ui.tabs :items="[
    [
        'label' => 'Denetim kayıtları',
        'url' => route('admin.kayitlar.denetim'),
        'active' => $aktif === 'denetim',
    ],
    [
        'label' => 'Hata kayıtları',
        'url' => route('admin.kayitlar.hatalar'),
        'active' => $aktif === 'hata',
    ],
]" />
