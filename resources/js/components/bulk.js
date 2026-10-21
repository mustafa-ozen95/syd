import { on } from '../lib/dom.js';

/**
 * Liste ekranlarındaki TOPLU SEÇİM çubuğu.
 *
 * Kaç satır seçildiğini söyler ve seçim boşken düğmeyi kapalı tutar. Sayıyı
 * ekranda tutmak, "hangi kayıtlara uygulanacak" sorusunu tıklamadan
 * yanıtlar. Buradaki iş yalnızca seçim ve sayaç.
 */
function tazele() {
    const kutular = [...document.querySelectorAll('[data-bulk-item]')];
    const secili = kutular.filter((kutu) => kutu.checked);

    const sayac = document.querySelector('[data-bulk-count]');
    const dugme = document.querySelector('[data-bulk-submit]');
    const hepsi = document.querySelector('[data-bulk-all]');

    if (sayac) {
        sayac.textContent = String(secili.length);
    }

    if (dugme) {
        dugme.disabled = secili.length === 0;
    }

    if (hepsi) {
        hepsi.checked = kutular.length > 0 && secili.length === kutular.length;

        /* Kısmi seçim: ne boş ne tam. Üçüncü hâli göstermezsek yanıltır. */
        hepsi.indeterminate = secili.length > 0 && secili.length < kutular.length;
    }
}

export function init() {
    /*
     * Delegasyon: satırlar sayfa geçişinde yenileniyor. Doğrudan bağlansaydı
     * ilk geçişten sonra seçim çubuğu ölürdü.
     */
    on('change', '[data-bulk-all]', (olay) => {
        document.querySelectorAll('[data-bulk-item]:not(:disabled)').forEach((kutu) => {
            kutu.checked = olay.target.checked;
        });
    });

    on('change', '[data-bulk-item], [data-bulk-all]', tazele);
}

/** Her çizimde: yeni gelen listede sayaç sıfırdan doğru olmalı. */
export function prepare() {
    tazele();
}
