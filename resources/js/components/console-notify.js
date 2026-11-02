import * as toast from './toast.js';

/**
 * "İş bitti" bildirimi (app/ConsoleModule, IsBildirimleri).
 *
 * Kurulum saatler sürebiliyor: panelin hangi sayfasında olunursa olunsun,
 * işi açanın işi bitince köşede bildirim çıkar; sekme arka plandaysa ve
 * tarayıcı izin verdiyse masaüstü bildirimi de. Ne zaman yeniden
 * sorulacağını sunucu söyler (açık iş varken sık).
 *
 * Adres sabit: yönlendirmeyi panel değil bu modül kuruyor. Yetkisi olmayan
 * kişide yanıt JSON değildir (yasak sayfasına yönlenir): yoklama durur.
 */
const ADDRESS = '/console/notifications';
const RETRY_SECONDS = 120;

let started = false;

function desktop(item) {
    if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) {
        return;
    }

    const note = new Notification('Konsol kurulumu', { body: item.mesaj, tag: item.url });

    note.onclick = () => {
        window.focus();
        window.location.href = item.url;
    };
}

async function poll() {
    let next = RETRY_SECONDS;

    try {
        const response = await fetch(new URL(ADDRESS, document.baseURI), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            redirect: 'manual',
        });

        if (!response.ok || !(response.headers.get('content-type') || '').includes('json')) {
            return;
        }

        const data = await response.json();
        next = data.sonraki || RETRY_SECONDS;

        data.bildirimler.forEach((item) => {
            toast.emit(item.mesaj, item.durum === 'tamam' ? 'success' : 'danger');
            desktop(item);
        });
    } catch {
        /* Ağ kesintisi: bir süre sonra yeniden denenir. */
    }

    setTimeout(poll, next * 1000);
}

/*
 * Masaüstü bildirimi için izin, iş açılırken istenir: tarayıcı izni yalnız
 * bir tıklamanın içinde sordurur.
 */
function askOnJobSubmit(event) {
    if (event.target.matches('[data-console-job-form]') && 'Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

export function init() {
    /* Yalnız oturum açıkken (üst çubukta kullanıcı menüsü var). */
    if (started || !document.querySelector('[data-menu-open]')) {
        return;
    }

    started = true;
    document.addEventListener('submit', askOnJobSubmit);
    poll();
}
