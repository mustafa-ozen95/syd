import { navigate } from './navigation.js';

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
const THRESHOLD = 70;

/**
 * Dikey kaçış toleransı.
 *
 * Aşağı kaydırırken parmak hiçbir zaman tam dikey gitmiyor; küçük bir yatay
 * bileşen her jestte var. Dikey yol yataydan büyükse bu bir kaydırmadır,
 * bölüm değiştirme değil.
 */
const VERTICAL_RATIO = 1.0;

/** Uzun basıp sürüklemeler (metin seçimi, sürükle-bırak) sayılmaz. */
const MAX_DURATION = 800;

let start = null;

/**
 * Parmağın altındaki kutulardan biri o yöne kayabiliyor mu?
 *
 * @param {Element} hedef
 * @param {number} yon  -1 sola (sonraki), +1 sağa (önceki)
 */
function canScrollSelf(target, direction) {
    let el = target instanceof Element ? target : null;

    while (el && el !== document.body) {
        const style = getComputedStyle(el);

        if (/(auto|scroll)/.test(style.overflowX) && el.scrollWidth > el.clientWidth + 1) {
            const remaining = direction < 0
                ? el.scrollWidth - el.clientWidth - el.scrollLeft
                : el.scrollLeft;

            if (remaining > 1) {
                return true;
            }
        }

        el = el.parentElement;
    }

    return false;
}

/** Menüdeki bölümler ve şu an hangisindeyiz. */
function sections() {
    const links = [...document.querySelectorAll('.topbar__nav .topbar__link')];
    const index = links.findIndex((a) => a.getAttribute('aria-current') === 'page');

    return { links, index };
}

function switchSection(direction) {
    const { links, index } = sections();

    if (links.length === 0 || index === -1) {
        return;
    }

    /* Uçlarda dönülmez: son bölümden sonrası yok, ilk bölümden öncesi de. */
    const target = links[index + (direction < 0 ? 1 : -1)];

    if (target) {
        navigate(target.href);
    }
}

export function init() {
    document.addEventListener('touchstart', (event) => {
        if (event.touches.length !== 1) {
            start = null;

            return;
        }

        const touch = event.touches[0];

        start = {
            x: touch.clientX,
            y: touch.clientY,
            at: Date.now(),
            target: event.target,
        };
    }, { passive: true });

    document.addEventListener('touchend', (event) => {
        if (start === null || event.changedTouches.length !== 1) {
            return;
        }

        const touch = event.changedTouches[0];
        const dx = touch.clientX - start.x;
        const dy = touch.clientY - start.y;
        const duration = Date.now() - start.at;
        const begun = start;

        start = null;

        if (duration > MAX_DURATION || Math.abs(dx) < THRESHOLD || Math.abs(dx) < Math.abs(dy) * VERTICAL_RATIO) {
            return;
        }

        const direction = dx < 0 ? -1 : 1;

        if (canScrollSelf(begun.target, direction)) {
            return;
        }

        switchSection(direction);
    }, { passive: true });
}
