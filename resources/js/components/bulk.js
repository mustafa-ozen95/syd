import { on } from '../lib/dom.js';

/**
 * Liste ekranlarındaki TOPLU SEÇİM çubuğu.
 *
 * Kaç satır seçildiğini söyler ve seçim boşken düğmeyi kapalı tutar. Sayıyı
 * ekranda tutmak, "hangi kayıtlara uygulanacak" sorusunu tıklamadan
 * yanıtlar. Buradaki iş yalnızca seçim ve sayaç.
 */
function refresh() {
    const boxes = [...document.querySelectorAll('[data-bulk-item]')];
    const selected = boxes.filter((box) => box.checked);

    const counter = document.querySelector('[data-bulk-count]');
    const button = document.querySelector('[data-bulk-submit]');
    const all = document.querySelector('[data-bulk-all]');

    if (counter) {
        counter.textContent = String(selected.length);
    }

    if (button) {
        button.disabled = selected.length === 0;
    }

    if (all) {
        all.checked = boxes.length > 0 && selected.length === boxes.length;

        /* Kısmi seçim: ne boş ne tam. Üçüncü hâli göstermezsek yanıltır. */
        all.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}

export function init() {
    /*
     * Delegasyon: satırlar sayfa geçişinde yenileniyor. Doğrudan bağlansaydı
     * ilk geçişten sonra seçim çubuğu ölürdü.
     */
    on('change', '[data-bulk-all]', (event) => {
        document.querySelectorAll('[data-bulk-item]:not(:disabled)').forEach((box) => {
            box.checked = event.target.checked;
        });
    });

    on('change', '[data-bulk-item], [data-bulk-all]', refresh);
}

/** Her çizimde: yeni gelen listede sayaç sıfırdan doğru olmalı. */
export function prepare() {
    refresh();
}
