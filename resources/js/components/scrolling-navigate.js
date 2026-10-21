import { git } from './navigation.js';

/**
 * Telefonda parmakla bölüm değiştirme.
 *
 * Üst menü dar ekranda iki satıra iniyor ve bölümler arasında gezinmek her
 * seferinde menüye uzanmak demek; parmakla kaydırmak daha doğal.
 *
 * KURAL: sola kaydırma SONRAKİ, sağa kaydırma ÖNCEKİ bölüme götürür. Sıra
 * menüdeki sıradır — ekranda görünen neyse o. Menü tek kaynaktır; burada
 * ikinci bir bölüm listesi TUTULMAZ.
 *
 * JEST ÖNCE KENDİ KAPSAYICISININ: bölme şeritleri, geniş
 * tablolar ve sekme şeritleri yatay kayar. Parmağın altındaki kutu o yöne
 * kayabiliyorsa jest ona bırakılır; ancak sona gelmiş (ya da hiç kaymayan)
 * bir kutuda bölüm değişir. Aksi hâlde bölmeler arasında gezinmek
 * imkânsız olurdu.
 *
 * Yalnızca DOKUNMATİK olayları dinlenir: farede yatay sürükleme metin
 * seçmektir ve onu bölüm değişimine çevirmek kimsenin beklemediği bir şey
 * olurdu.
 */

/** Bölüm değişmesi için gereken yatay yol. */
const ESIK = 70;

/**
 * Dikey kaçış toleransı.
 *
 * Aşağı kaydırırken parmak hiçbir zaman tam dikey gitmiyor; küçük bir yatay
 * bileşen her jestte var. Dikey yol yataydan büyükse bu bir kaydırmadır,
 * bölüm değiştirme değil.
 */
const DIKEY_PAY = 1.0;

/** Uzun basıp sürüklemeler (metin seçimi, sürükle-bırak) sayılmaz. */
const AZAMI_SURE = 800;

let baslangic = null;

/**
 * Parmağın altındaki kutulardan biri o yöne kayabiliyor mu?
 *
 * @param {Element} hedef
 * @param {number} yon  -1 sola (sonraki), +1 sağa (önceki)
 */
function kendiKayabilir(hedef, yon) {
    let el = hedef instanceof Element ? hedef : null;

    while (el && el !== document.body) {
        const stil = getComputedStyle(el);

        if (/(auto|scroll)/.test(stil.overflowX) && el.scrollWidth > el.clientWidth + 1) {
            const kalan = yon < 0
                ? el.scrollWidth - el.clientWidth - el.scrollLeft
                : el.scrollLeft;

            if (kalan > 1) {
                return true;
            }
        }

        el = el.parentElement;
    }

    return false;
}

/** Menüdeki bölümler ve şu an hangisindeyiz. */
function bolumler() {
    const baglantilar = [...document.querySelectorAll('.topbar__nav .topbar__link')];
    const sira = baglantilar.findIndex((a) => a.getAttribute('aria-current') === 'page');

    return { baglantilar, sira };
}

function gecis(yon) {
    const { baglantilar, sira } = bolumler();

    if (baglantilar.length === 0 || sira === -1) {
        return;
    }

    /* Uçlarda dönülmez: son bölümden sonrası yok, ilk bölümden öncesi de. */
    const hedef = baglantilar[sira + (yon < 0 ? 1 : -1)];

    if (hedef) {
        git(hedef.href);
    }
}

export function init() {
    document.addEventListener('touchstart', (olay) => {
        if (olay.touches.length !== 1) {
            baslangic = null;

            return;
        }

        const dokunus = olay.touches[0];

        baslangic = {
            x: dokunus.clientX,
            y: dokunus.clientY,
            an: Date.now(),
            hedef: olay.target,
        };
    }, { passive: true });

    document.addEventListener('touchend', (olay) => {
        if (baslangic === null || olay.changedTouches.length !== 1) {
            return;
        }

        const dokunus = olay.changedTouches[0];
        const dx = dokunus.clientX - baslangic.x;
        const dy = dokunus.clientY - baslangic.y;
        const sure = Date.now() - baslangic.an;
        const bas = baslangic;

        baslangic = null;

        if (sure > AZAMI_SURE || Math.abs(dx) < ESIK || Math.abs(dx) < Math.abs(dy) * DIKEY_PAY) {
            return;
        }

        const yon = dx < 0 ? -1 : 1;

        if (kendiKayabilir(bas.hedef, yon)) {
            return;
        }

        gecis(yon);
    }, { passive: true });
}
