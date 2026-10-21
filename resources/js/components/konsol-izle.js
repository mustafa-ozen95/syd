/**
 * Konsol kurulumunun canlı ekranı (app/Konsol, is.blade.php).
 *
 * `#konsol-izle` üzerindeki `data-akis` adresi yoklanır: yeni çıktı ekrana
 * eklenir, durum ve adımlar yenilenir. İş kapanınca son bir kez okunup
 * durulur. Panelden yazılanlar `data-girdi` adresine gider; ajan bir sonraki
 * aktarımda alır.
 *
 * Yenilemesiz geçişte satır içi betik çalışmadığı için her çizimde
 * app.js'ten `init()` çağrılır; aynı iş için
 * ikinci döngü kurulmaz.
 */

const YOKLAMA_MS = 1500;
const EKRAN_SINIRI = 400_000;

let aktif = null;

/* Terminal kaçış dizileri ve satır başı ekranda görünmesin. */
function temizle(metin) {
    return metin
        // eslint-disable-next-line no-control-regex
        .replace(/\x1b\[[0-9;?]*[A-Za-z]|\x1b[()][0-9A-Za-z]|\x00|\x07/g, '')
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '');
}

/*
 * Geri silme (\b): EX2300 açılışta "/-\|" döndürüyor, her karakterden sonra
 * bir \b. Ekranda kutu olarak görünüyordu; önceki karakteri siler.
 */
function geriSil(metin) {
    if (!metin.includes('\x08')) {
        return metin;
    }

    let onceki;

    do {
        onceki = metin;
        /* \x08 yazılmalı: düz `\b` sınıf dışında "kelime sınırı" demek. */
        metin = metin.replace(/[^\n\x08]\x08/g, '');
    } while (metin !== onceki);

    return metin.replace(/\x08/g, '');
}

function adimlariYaz(govde, olaylar) {
    govde.replaceChildren(...olaylar.map((o) => {
        const satir = document.createElement('tr');

        [o.zaman, o.asama, o.mesaj, o.kaynak].forEach((deger, i) => {
            const hucre = document.createElement('td');
            hucre.dataset.label = ['Zaman', 'Adım', 'Açıklama', 'Kaynak'][i];
            hucre.textContent = deger ?? '';

            if (i === 0) {
                hucre.className = 'table__cell--mono text-sm';
            } else if (i > 1) {
                hucre.className = 'text-sm';
            }

            if (i === 1 && (o.uyari || o.hata)) {
                hucre.className = 'konsol-uyari';
            }

            satir.append(hucre);
        });

        return satir;
    }));
}

function durumuYaz(kok, veri) {
    const rozet = kok.querySelector('[data-konsol-durum]');

    if (rozet) {
        rozet.className = `badge badge--${veri.renk}`;
        rozet.textContent = veri.durum;
    }

    const mesaj = kok.querySelector('[data-konsol-mesaj]');

    if (mesaj) {
        mesaj.textContent = veri.bekliyor ? `Sizden bekleniyor: ${veri.sonMesaj}` : veri.sonMesaj;
        mesaj.classList.toggle('konsol-uyari', veri.bekliyor);
    }

    ilerlemeyiYaz(kok, veri.ilerleme);

    if (!veri.acik) {
        kok.querySelector('[data-konsol-girdi]')?.remove();
    }
}

function mb(bayt) {
    return (bayt / 1048576).toLocaleString('tr-TR', { maximumFractionDigits: 1 });
}

/* TFTP'den inen görüntü: yüzde, hız, kalan süre. */
function ilerlemeyiYaz(kok, i) {
    const alan = kok.querySelector('[data-konsol-ilerleme]');

    if (!alan) {
        return;
    }

    alan.classList.toggle('is-hidden', !i);

    if (!i) {
        return;
    }

    alan.querySelector('[data-konsol-ilerleme-cubuk]').value = i.yuzde;

    let durum = 'Görüntü indiriliyor (TFTP)';
    let hiz = `${mb(i.hiz)} MB/sn`;

    if (i.hata) {
        durum = 'Görüntü aktarımı kesildi';
        hiz = i.hata;
    } else if (i.bitti) {
        durum = 'Görüntü indi; cihaz kuruyor';
        hiz = '';
    } else if (i.kalan !== null) {
        hiz += ` · kalan ~${Math.max(1, Math.round(i.kalan / 60))} dk`;
    }

    alan.querySelector('[data-konsol-ilerleme-metin]').textContent = `${durum} · ${mb(i.giden)} / ${mb(i.boyut)} MB · %${i.yuzde}`;
    alan.querySelector('[data-konsol-ilerleme-hiz]').textContent = hiz;
}

/*
 * Konsol boşken ne beklendiği yazar: "elektrik verin" uyarısı geldiyse o;
 * "ajan bekleniyor" demek operatöre ne yapacağını söylemez.
 */
function bosEkran(veri) {
    if (!veri.acik) {
        return 'Konsolda bir şey görülmedi.';
    }

    if (veri.bekliyor) {
        return `Sizden bekleniyor:\n${veri.sonMesaj}`;
    }

    return veri.ajanVar ? 'Konsol açık; cihazdan çıktı bekleniyor…' : 'Ajan PC\'ye kuruluyor…';
}

function baslat(kok) {
    const ekran = kok.querySelector('[data-konsol-ekran]');
    const adimlar = document.querySelector('[data-konsol-adimlar]');
    const durum = { sonra: 0, ilk: true, bos: true, kok };

    aktif = durum;

    async function yokla() {
        if (aktif !== durum || !document.body.contains(kok)) {
            return;
        }

        let veri;

        try {
            const yanit = await fetch(`${kok.dataset.akis}?sonra=${durum.sonra}`, { headers: { Accept: 'application/json' } });

            if (!yanit.ok) {
                throw new Error(String(yanit.status));
            }

            veri = await yanit.json();
        } catch {
            setTimeout(yokla, YOKLAMA_MS * 3);

            return;
        }

        if (aktif !== durum) {
            return;
        }

        const gelen = temizle(veri.metin);

        /* Boş ekranın yazısı her yoklamada tazelenir ("elektrik verin" sonradan gelir). */
        if (durum.bos && gelen === '' && !durum.ilk) {
            ekran.textContent = bosEkran(veri);
        }

        if (durum.ilk || gelen !== '') {
            const altta = ekran.scrollHeight - ekran.scrollTop - ekran.clientHeight < 40;
            /* İlk çizimde ya da yer tutucu dururken ekran baştan yazılır. */
            const onceki = durum.ilk || durum.bos ? '' : ekran.textContent;
            const yeni = geriSil(onceki + gelen);

            durum.bos = yeni === '';
            ekran.textContent = durum.bos ? bosEkran(veri) : yeni.slice(-EKRAN_SINIRI);

            if (altta || durum.ilk) {
                ekran.scrollTop = ekran.scrollHeight;
            }
        }

        durum.ilk = false;
        durum.sonra = veri.son;
        durumuYaz(kok, veri);

        if (adimlar) {
            adimlariYaz(adimlar, veri.olaylar);
        }

        if (veri.devami) {
            setTimeout(yokla, 50);
        } else if (veri.acik) {
            setTimeout(yokla, YOKLAMA_MS);
        } else if (kok.dataset.acik === '1') {
            /* Kapanış anında gelen son çıktı için bir tur daha. */
            kok.dataset.acik = '0';
            setTimeout(yokla, YOKLAMA_MS);
        }
    }

    yokla();
}

async function gonder(kok, govde) {
    const yanit = await fetch(kok.dataset.girdi, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(govde),
    });

    return yanit.ok;
}

function girdiKur(kok) {
    const form = kok.querySelector('[data-konsol-girdi]');

    if (!form || form.dataset.kuruldu) {
        return;
    }

    form.dataset.kuruldu = '1';

    form.addEventListener('submit', async (olay) => {
        olay.preventDefault();
        const alan = form.elements.namedItem('satir');
        const satir = alan.value;

        if (await gonder(kok, satir === '' ? { tus: 'enter' } : { satir })) {
            alan.value = '';
        }

        alan.focus();
    });

    form.querySelectorAll('[data-konsol-tus]').forEach((dugme) => {
        dugme.addEventListener('click', () => gonder(kok, { tus: dugme.dataset.konsolTus }));
    });
}

export function init() {
    const kok = document.getElementById('konsol-izle');

    if (!kok) {
        aktif = null;

        return;
    }

    girdiKur(kok);

    if (aktif?.kok === kok) {
        return;
    }

    baslat(kok);
}
