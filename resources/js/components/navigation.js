import { on } from '../lib/dom.js';

/**
 * Sayfa geçişleri — tarayıcı yenilemeden.
 *
 * Sunucu yine tam sayfa üretir; değişen tek şey, tarayıcının o sayfayı
 * baştan çizmek yerine gövdeyi yerinde değiştirmesi. Böylece tema, yazı
 * tipleri ve betikler yeniden yüklenmez, ekran beyaza düşmez.
 *
 * NEDEN KÜTÜPHANE DEĞİL: bileşenlerin neredeyse tamamı olayları zaten
 * document üzerinde dinliyor (bkz. lib/dom.js `on`), yani gövde değişince
 * kendiliğinden çalışmaya devam ediyorlar. Geriye kalan iş bir istek, bir
 * DOM değişimi ve geçmiş kaydı; bunun için paket eklemek, davranışını
 * denetlemediğimiz bir katmanı panelin önüne koymak olurdu.
 *
 * SUNUCU TARAFINDA HİÇBİR ŞEY DEĞİŞMEZ. Yetki, doğrulama ve yönlendirme
 * aynı yerlerde; bu dosya yalnızca gösterimle ilgilenir. İstek başarısız
 * olursa ya da HTML dönmezse tarayıcının kendi gezinmesine düşülür — yani
 * en kötü durumda eski davranış.
 *
 * POST FORMLARI DOKUNULMAZ. Onlar onay penceresinden geçiyor, yeni sekme
 * açabiliyor ve yönlendirmeyle bitiyor; araya girmek kazanılan şeye değmez.
 */

const EVENT_NAME = 'sys:page';

/** Devam eden istek: hızlı tıklamada eskisi iptal edilir. */
let request = null;

function sameOrigin(url) {
    return url.origin === window.location.origin;
}

/** Bu bağlantı bizim işimiz mi? */
function isHandled(link) {
    const href = link.getAttribute('href') ?? '';

    return href !== ''
        && ! href.startsWith('#')
        && ! link.target
        && ! link.hasAttribute('download')
        && link.dataset.fullReload === undefined
        && sameOrigin(new URL(link.href, window.location.href));
}

function render(text, url, history) {
    const next = new DOMParser().parseFromString(text, 'text/html');

    document.title = next.title;

    /*
     * <head> yerinde kalır (biçem ve betikler yeniden yüklenmesin diye) ama
     * CSRF simgesi ORADADIR: oturum tazelenirse eski simge DOM'da kalır ve
     * AJAX istekleri 419 alırdı. Sunucunun yeni sayfada verdiği simge
     * alınır.
     */
    const token = next.querySelector('meta[name="csrf-token"]')?.content;
    const current = document.querySelector('meta[name="csrf-token"]');

    if (token && current) {
        current.content = token;
    }

    document.body.innerHTML = next.body.innerHTML;

    if (history === 'push') {
        window.history.pushState({ sys: true }, '', url);
        window.scrollTo(0, 0);
    }

    /* Düğüme bağlı çalışan bileşenler kendilerini burada tazeler. */
    document.dispatchEvent(new CustomEvent(EVENT_NAME));
}

export async function navigate(url, history = 'push') {
    request?.abort();
    request = new AbortController();

    document.body.dataset.transition = '';

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
            signal: request.signal,
        });

        /*
         * HTML değilse (dosya indirme, beklenmedik bir yanıt) araya
         * girmeyiz: tarayıcı kendi bildiğini yapsın.
         */
        if (! response.ok || ! (response.headers.get('content-type') ?? '').includes('text/html')) {
            window.location.href = url;

            return;
        }

        /* Yönlendirme olduysa adres çubuğunda VARILAN yer yazmalı (örn. giriş ekranı). */
        render(await response.text(), response.url, history);
    } catch (error) {
        if (error.name !== 'AbortError') {
            window.location.href = url;
        }
    } finally {
        delete document.body.dataset.transition;
    }
}

/**
 * Formu YERİNDE gönderir: sayfa yenilenmez.
 *
 * POST formlarına genel olarak dokunulmuyor (bkz. dosya başı) ama uzun süren
 * bir işi BAŞLATAN form istisnadır: gönderim tam sayfa yenilemesi yapıyor,
 * ekran beyaza düşüyor ve kullanıcı işin başladığını ancak yeni sayfa
 * çizilince görüyordu — tam da ilerlemeyi izlemek istediği anda.
 *
 * Sunucu tarafında hiçbir şey değişmez: aynı uç, aynı yönlendirme, aynı
 * bildirim. Yalnızca dönen sayfa tarayıcıyı yenilemek yerine gövdeye
 * çizilir; böylece bildirim ve az önce açılan panel yerinde kalır.
 *
 * İstek başarısız olursa ya da HTML dönmezse formun KENDİ gönderimine
 * düşülür — en kötü durumda eski davranış.
 */
export async function submitForm(form) {
    const url = form.getAttribute('action') || window.location.href;

    document.body.dataset.transition = '';

    try {
        const response = await fetch(url, {
            method: (form.getAttribute('method') || 'post').toUpperCase(),
            body: new FormData(form),
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        });

        if (! response.ok || ! (response.headers.get('content-type') ?? '').includes('text/html')) {
            form.submit();

            return;
        }

        /* Yönlendirme sonrası VARILAN adres yazılır; geçmişe kayıt eklenmez. */
        render(await response.text(), response.url, 'none');
    } catch {
        form.submit();
    } finally {
        delete document.body.dataset.transition;
    }
}

/**
 * Bulunulan sayfayı YERİNDE tazeler.
 *
 * `location.reload()` ile aynı sonucu vermez: tarayıcı yenilemesi ekranı
 * beyaza düşürür, tema ve betikleri baştan yükler, açık olan sağ paneli ve
 * kaydırma konumunu kaybettirir. Uzun süren bir iş bittiğinde kullanıcıyı
 * bulunduğu yerden koparmanın bir sebebi yok — sunucu yine tam sayfa
 * üretir, yalnızca gövde yerinde değişir.
 *
 * Geçmişe kayıt EKLENMEZ: tazeleme bir gezinme değil, aynı sayfanın yeni
 * hâli. Eklenseydi "geri" tuşu aynı adrese tekrar tekrar düşerdi.
 */
export function refresh() {
    return navigate(window.location.href, 'none');
}

export function init() {
    on('click', 'a[href]', (event, link) => {
        /*
         * Başka bir bileşen (pencere açma, onay penceresi) tıklamayı zaten
         * karşıladıysa karışmayız.
         */
        if (event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        if (! isHandled(link)) {
            return;
        }

        event.preventDefault();
        navigate(link.href);
    });

    /* Arama ve süzgeç de birer sayfa geçişidir. */
    on('submit', 'form', (event, form) => {
        if (event.defaultPrevented || form.target
            || (form.getAttribute('method') ?? 'get').toLowerCase() !== 'get') {
            return;
        }

        const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);

        if (! sameOrigin(url)) {
            return;
        }

        url.search = new URLSearchParams(new FormData(form)).toString();

        event.preventDefault();
        navigate(url.href);
    });

    /* Geri/ileri: adres zaten değişti, yalnızca içerik getirilir. */
    window.addEventListener('popstate', () => {
        navigate(window.location.href, 'none');
    });

    /*
     * İlk sayfanın da bir geçmiş kaydı olmalı; yoksa ilk "geri" tuşu
     * boş duruma düşerdi.
     */
    window.history.replaceState({ sys: true }, '', window.location.href);
}
