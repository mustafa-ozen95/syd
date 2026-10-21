/**
 * Konsol "Yeni iş" formu (app/ConsoleModule, is-fields.blade.php).
 *
 * "Seçilen yedek" dışında yedek cihazı ve dosyası gizlenir; ilk görünüm sunucudan doğru gelir, burada
 * yalnız değişiklik izlenir. Yedek cihazı seçilince o cihazın yedekleri
 * `data-adres`ten çekilip dosya kutusuna doldurulur.
 *
 * Dinleyici belgede, bir kez kurulur: pencere yenilemesiz geçişte yeniden
 * çizilse de çalışır.
 */

let istekNo = 0;

function gorunumuYenile(form) {
    const yapilandirma = form.querySelector('[data-konsol-yapilandirma]')?.value;

    form.querySelectorAll('[data-konsol-sadece]').forEach((alan) => {
        alan.classList.toggle('is-hidden', alan.dataset.konsolSadece !== yapilandirma);
    });
}

function secenek(deger, metin) {
    const o = document.createElement('option');
    o.value = deger;
    o.textContent = metin;

    return o;
}

async function yedekleriDoldur(cihazKutusu) {
    const dosyaKutusu = cihazKutusu.form.querySelector('[data-konsol-yedek-dosya]');

    if (!dosyaKutusu) {
        return;
    }

    const no = ++istekNo;
    const cihaz = cihazKutusu.value;

    if (cihaz === '') {
        dosyaKutusu.replaceChildren(secenek('', 'Önce cihazı seçin'));

        return;
    }

    dosyaKutusu.replaceChildren(secenek('', 'Yükleniyor…'));
    dosyaKutusu.disabled = true;

    try {
        const yanit = await fetch(`${cihazKutusu.dataset.adres}?cihaz=${encodeURIComponent(cihaz)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!yanit.ok) {
            throw new Error(String(yanit.status));
        }

        const { yedekler } = await yanit.json();

        if (no !== istekNo) {
            return;
        }

        dosyaKutusu.replaceChildren(
            secenek('', yedekler.length ? '— seçin —' : 'Bu cihazın yedeği yok'),
            ...yedekler.map((y) => secenek(y.dosya, y.etiket)),
        );
    } catch {
        if (no === istekNo) {
            dosyaKutusu.replaceChildren(secenek('', 'Liste alınamadı; cihazı yeniden seçin'));
        }
    } finally {
        if (no === istekNo) {
            dosyaKutusu.disabled = false;
        }
    }
}

export function init() {
    document.addEventListener('change', (olay) => {
        const hedef = olay.target;

        if (!(hedef instanceof HTMLSelectElement) || !hedef.form) {
            return;
        }

        if (hedef.matches('[data-konsol-adres]')) {
            hedef.form.querySelectorAll('[data-konsol-sabit]').forEach((alan) => {
                alan.classList.toggle('is-hidden', hedef.value === 'dhcp');
            });
        } else if (hedef.matches('[data-konsol-pc]')) {
            /* PC değişince adres onun kaydındakiyle dolar. */
            const secili = hedef.selectedOptions[0];
            const ip = hedef.form.querySelector('[data-konsol-ip]');
            const gecit = hedef.form.querySelector('[data-konsol-gecit]');

            if (secili && ip && gecit) {
                ip.value = secili.dataset.ip ?? '';
                gecit.value = secili.dataset.gecit ?? '';
            }
        } else if (hedef.matches('[data-konsol-yapilandirma]')) {
            gorunumuYenile(hedef.form);

            /* "Seçilen yedek"e geçince cihaz boşsa "Cihaz" alanındaki ad önerilir. */
            const cihazKutusu = hedef.form.querySelector('[data-konsol-yedek-cihaz]');
            const ad = hedef.form.querySelector('[data-konsol-cihaz]')?.value;

            if (hedef.value === 'yedek' && cihazKutusu && cihazKutusu.value === ''
                && [...cihazKutusu.options].some((o) => o.value === ad)) {
                cihazKutusu.value = ad;
                yedekleriDoldur(cihazKutusu);
            }
        } else if (hedef.matches('[data-konsol-yedek-cihaz]')) {
            yedekleriDoldur(hedef);
        }
    });
}
