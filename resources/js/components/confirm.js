import { $, on } from '../lib/dom.js';
import { submitForm } from './navigation.js';

/**
 * Tehlikeli işlemler için onay penceresi.
 *
 * Kullanımı — herhangi bir form veya bağlantıya öznitelik eklemek yeterlidir:
 *   data-confirm="Bu kayıt silinecek."
 *   data-confirm-title="Kaydı sil"
 *   data-confirm-variant="danger"
 *   data-confirm-verify="kayit-1"   (kullanıcının yazarak doğrulaması istenir)
 *   data-confirm-reasonless      (gerekçe istenmez — yalnızca okuyan işlemler)
 *
 * Gerekçe varsayılan olarak ZORUNLUDUR ve forma "reason" alanı olarak
 * eklenir; sunucu tarafında da aranır, yani pencereyi atlamak işe yaramaz.
 */

let pending = null;

function dialog() {
    return $('[data-confirm-dialog]');
}

function open(trigger) {
    const box = dialog();

    if (!box) {
        return false;
    }

    pending = trigger;

    box.querySelector('[data-confirm-title]').textContent =
        trigger.dataset.confirmTitle || 'Onay gerekiyor';
    box.querySelector('[data-confirm-message]').textContent = trigger.dataset.confirm;

    const verify = trigger.dataset.confirmVerify ?? '';
    const verifyField = box.querySelector('[data-confirm-verify-field]');
    const input = box.querySelector('[data-confirm-input]');
    const accept = box.querySelector('[data-confirm-accept]');
    const reasonField = box.querySelector('[data-confirm-reason-field]');
    const reason = box.querySelector('[data-confirm-reason]');

    // Gerekçe yalnızca durumu değiştirmeyen işlemlerde istenmez.
    const reasonless = trigger.dataset.confirmReasonless !== undefined;

    /*
     * GEREKÇE ALANI BU PANELDE YOK (bkz. confirm-modal.blade.php). Betik onu
     * varmış gibi kullanırsa `null.hidden` hatası atar, olay engellenmeden
     * kalır ve form ONAYSIZ gider. Alan yoksa gerekçe istenmez.
     */
    const skipReason = reasonless || !reasonField || !reason;

    verifyField.hidden = verify === '';
    if (reasonField) {
        reasonField.hidden = skipReason;
    }
    box.querySelector('[data-confirm-expected]').textContent = verify;
    input.value = '';
    if (reason) {
        reason.value = '';
    }

    accept.className = `btn btn--${trigger.dataset.confirmVariant === 'danger' ? 'danger' : 'primary'}`;

    const refresh = () => {
        const verified = verify === '' || input.value.trim() === verify;
        const hasReason = skipReason || reason.value.trim().length >= 3;

        accept.disabled = !verified || !hasReason;
    };

    input.oninput = refresh;
    if (reason) {
        reason.oninput = refresh;
    }
    refresh();

    box.hidden = false;
    (skipReason ? (verify === '' ? accept : input) : reason).focus();

    return true;
}

function close() {
    const box = dialog();

    if (box) {
        box.hidden = true;
    }

    pending = null;
}

function proceed() {
    const trigger = pending;
    const reason = dialog()?.querySelector('[data-confirm-reason]')?.value?.trim() ?? '';

    close();

    if (!trigger) {
        return;
    }

    // İkinci turda onay istenmesin diye öznitelik kaldırılır.
    delete trigger.dataset.confirm;

    if (trigger.tagName === 'FORM') {
        // Gerekçe forma taşınır; sunucu onu arar.
        if (reason !== '') {
            const field = document.createElement('input');

            field.type = 'hidden';
            field.name = 'reason';
            field.value = reason;
            trigger.appendChild(field);
        }

        /*
         * Gönderimden ÖNCE haber verilir; onaydan sonra iş yapacak bir
         * bileşen bu olayı dinler. submit() "submit" olayını TETİKLEMEZ,
         * requestSubmit() ise toplu güncelleme formunu bozar: o formun
         * varsayılan düğmesi seçim boşken kapalı durur ve gönderim sessizce
         * düşer. Bu yüzden kendi olayımız.
         */
        trigger.dispatchEvent(new CustomEvent('sys:confirmed', { bubbles: true }));

        /*
         * Uzun süren işi başlatan form YERİNDE gönderilir: tam sayfa
         * yenilemesi ekranı beyaza düşürüyor ve kullanıcı işin başladığını
         * ancak yeni sayfa çizilince görebiliyordu — tam da ilerlemeyi
         * izlemek istediği anda.
         */
        if (trigger.dataset.inPlace !== undefined) {
            submitForm(trigger);

            return;
        }

        trigger.submit();

        return;
    }

    /*
     * Form OLMAYAN tetikleyiciler (düğmeler) gerekçeyi kendi işleyicilerine
     * bu öznitelikle taşır: forma gizli alan eklemek gibi bir imkân yok ama
     * gerekçe yine de sunucuya gitmeli.
     */
    if (reason !== '') {
        trigger.dataset.reason = reason;
    }

    trigger.click();
}

/*
 * ONAY AÇILAMAZSA İŞLEM GİTMEZ. Pencere yoksa ya da açarken bir hata
 * çıkarsa tarayıcının kendi onay kutusu sorulur; işlem sorusuz gitmez.
 */
function askConfirm(event, trigger) {
    let opened = false;

    try {
        opened = open(trigger);
    } catch (error) {
        console.error(error);
    }

    if (opened) {
        event.preventDefault();

        return;
    }

    pending = null;

    // eslint-disable-next-line no-alert
    if (!window.confirm(trigger.dataset.confirm || 'Emin misiniz?')) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}

export function init() {
    on('submit', 'form[data-confirm]', askConfirm);

    on('click', 'a[data-confirm], button[data-confirm]', askConfirm);

    /* Onay istemeyen ama yerinde gönderilmesi gereken formlar. */
    on('submit', 'form[data-in-place]:not([data-confirm])', (event, form) => {
        event.preventDefault();
        submitForm(form);
    });

    on('click', '[data-confirm-accept]', proceed);
    on('click', '[data-confirm-cancel]', close);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog()?.hidden) {
            close();
        }
    });
}
