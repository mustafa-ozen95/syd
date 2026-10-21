{{--
    Tek örnek olarak layout'a yerleştirilir. Herhangi bir form, bağlantı veya
    düğmeye data-confirm özniteliği eklemek onay istemek için yeterlidir.
--}}
<div class="modal" data-confirm-dialog hidden role="dialog" aria-modal="true" aria-labelledby="confirm-title">
    <div class="modal__box">
        <header class="modal__header">
            <div>
                <h2 class="modal__title" id="confirm-title" data-confirm-title></h2>
            </div>
            <button type="button" class="modal__close" data-confirm-cancel aria-label="Kapat">&times;</button>
        </header>

        <div class="modal__body">
            <p data-confirm-message></p>

            {{--
                GEREKÇE ALANI BU PANELDE YOK.

                Ortak şablonda var çünkü gerekçenin denetim kaydına yazıldığı
                panellerde her işlem için "neden yaptın" sorusu denetimin
                parçası. Burada karşılığı yok: gerekçe hiçbir
                yere yazılmıyor ve alan duruyorsa her onay penceresi
                doldurulmayı bekleyen boş bir kutu gösterir (betik alanı
                zorunlu sayıyor). İhtiyaç olursa alan + denetim sütunu +
                zincir sürümü birlikte gelir.
            --}}

            <div class="field" data-confirm-verify-field hidden>
                <label class="field__label" for="confirm-input">
                    Onaylamak için <strong data-confirm-expected class="mono"></strong> yazın
                </label>
                <input type="text" class="input mono" id="confirm-input" data-confirm-input autocomplete="off">
            </div>
        </div>

        <footer class="modal__footer">
            <button type="button" class="btn btn--secondary" data-confirm-cancel>Vazgeç</button>
            <button type="button" class="btn btn--primary" data-confirm-accept>Onayla</button>
        </footer>
    </div>
</div>
