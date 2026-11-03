/**
 * Konsol kurulumunun canlı ekranı (app/ConsoleModule, is.blade.php).
 *
 * `#console-watch` üzerindeki `data-stream` adresi yoklanır: yeni çıktı ekrana
 * eklenir, durum ve adımlar yenilenir. İş kapanınca son bir kez okunup
 * durulur. Panelden yazılanlar `data-input` adresine gider; ajan bir sonraki
 * aktarımda alır.
 *
 * Yenilemesiz geçişte satır içi betik çalışmadığı için her çizimde
 * app.js'ten `init()` çağrılır; aynı iş için
 * ikinci döngü kurulmaz.
 */

const POLL_MS = 1500;

/*
 * Ekranda tutulan en çok karakter (~3000 satır). Tamamı "Konsol kaydı"nda.
 * 400 bindi: Junos açılışında 1 MB'ı aşan çıktıda ekran ağırlaşıyordu.
 */
const SCREEN_LIMIT = 150_000;

let active = null;

/* Terminal kaçış dizileri ve satır başı ekranda görünmesin. */
function clean(text) {
    return text
        // eslint-disable-next-line no-control-regex
        .replace(/\x1b\[[0-9;?]*[A-Za-z]|\x1b[()][0-9A-Za-z]|\x00|\x07/g, '')
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '');
}

/*
 * Geri silme (\b): EX2300 açılışta "/-\|" döndürüyor, her karakterden sonra
 * bir \b. Ekranda kutu olarak görünüyordu; önceki karakteri siler.
 */
function eraseBackspaces(text) {
    if (!text.includes('\x08')) {
        return text;
    }

    let previous;

    do {
        previous = text;
        /* \x08 yazılmalı: düz `\b` sınıf dışında "kelime sınırı" demek. */
        text = text.replace(/[^\n\x08]\x08/g, '');
    } while (text !== previous);

    return text.replace(/\x08/g, '');
}

/*
 * Yeni çıktıyı ekranın sonuna ekler; ekran BAŞTAN YAZILMAZ.
 *
 * Her yoklamada bütün ekranı (yüz binlerce karakter) okuyup geri silmeyi
 * baştan uygulamak ve yeniden çizdirmek, Junos açılışı gibi hızlı akan
 * çıktıda tarayıcıyı kilitliyordu. Tamamlanmış satırlar ayrı metin
 * düğümleri olarak eklenir ve bir daha işlenmez; yalnız son (yarım) satır
 * yeni gelenle birlikte işlenir: geri silme (\b) satır sonunu geçmez, dönen
 * çubuk ("/-\|") bu satırda kalır. Sınır aşılınca en eski düğümler düşer.
 */
function appendScreen(output, screen, incoming) {
    let text = incoming;

    /* Tek seferde sınırdan büyük gelen (ilk açılış): yalnız sonu, tam satırdan. */
    if (text.length > SCREEN_LIMIT) {
        text = text.slice(-SCREEN_LIMIT);
        text = text.slice(text.indexOf('\n') + 1);
    }

    const combined = eraseBackspaces(screen.tail.data + text);
    const cut = combined.lastIndexOf('\n') + 1;

    if (cut > 0) {
        const lines = document.createTextNode(combined.slice(0, cut));

        output.insertBefore(lines, screen.tail);
        screen.lines.push(lines);
        screen.length += lines.length;
    }

    /* Hiç satır sonu gelmeyen akış da sınırı aşmasın. */
    screen.tail.data = combined.slice(cut).slice(-SCREEN_LIMIT);

    while (screen.length > SCREEN_LIMIT && screen.lines.length > 1) {
        const oldest = screen.lines.shift();

        screen.length -= oldest.length;
        oldest.remove();
    }
}

/* Ekranı boşaltır: bundan sonra çıktı eklenerek yazılır. */
function resetScreen(output, screen) {
    screen.tail = document.createTextNode('');
    screen.lines = [];
    screen.length = 0;
    output.replaceChildren(screen.tail);
}

function writeSteps(tableBody, events) {
    tableBody.replaceChildren(...events.map((o) => {
        const row = document.createElement('tr');

        [o.zaman, o.asama, o.mesaj, o.kaynak].forEach((value, i) => {
            const cell = document.createElement('td');
            cell.dataset.label = ['Zaman', 'Adım', 'Açıklama', 'Kaynak'][i];
            cell.textContent = value ?? '';

            if (i === 0) {
                cell.className = 'table__cell--mono text-sm';
            } else if (i > 1) {
                cell.className = 'text-sm';
            }

            if (i === 1 && (o.uyari || o.hata)) {
                cell.className = 'console-warning';
            }

            row.append(cell);
        });

        return row;
    }));
}

function writeStatus(root, data) {
    const badge = root.querySelector('[data-console-status]');

    if (badge) {
        badge.className = `badge badge--${data.renk}`;
        badge.textContent = data.durum;
    }

    const message = root.querySelector('[data-console-message]');

    if (message) {
        message.textContent = data.bekliyor ? `Sizden bekleniyor: ${data.sonMesaj}` : data.sonMesaj;
        message.classList.toggle('console-warning', data.bekliyor);
    }

    writeProgress(root, data.ilerleme);

    if (!data.acik) {
        root.querySelector('[data-console-input]')?.remove();
    }
}

function mb(bytes) {
    return (bytes / 1048576).toLocaleString('tr-TR', { maximumFractionDigits: 1 });
}

/* TFTP'den inen görüntü: yüzde, hız, kalan süre. */
function writeProgress(root, i) {
    const field = root.querySelector('[data-console-progress]');

    if (!field) {
        return;
    }

    field.classList.toggle('is-hidden', !i);

    if (!i) {
        return;
    }

    field.querySelector('[data-console-progress-bar]').value = i.yuzde;

    let status = 'Görüntü indiriliyor (TFTP)';
    let speed = `${mb(i.hiz)} MB/sn`;

    if (i.hata) {
        status = 'Görüntü aktarımı kesildi';
        speed = i.hata;
    } else if (i.bitti) {
        status = 'Görüntü indi; cihaz kuruyor';
        speed = '';
    } else if (i.kalan !== null) {
        speed += ` · kalan ~${Math.max(1, Math.round(i.kalan / 60))} dk`;
    }

    field.querySelector('[data-console-progress-text]').textContent = `${status} · ${mb(i.giden)} / ${mb(i.boyut)} MB · %${i.yuzde}`;
    field.querySelector('[data-console-progress-speed]').textContent = speed;
}

/*
 * Konsol boşken ne beklendiği yazar: "elektrik verin" uyarısı geldiyse o;
 * "ajan bekleniyor" demek operatöre ne yapacağını söylemez.
 */
function emptyScreen(data) {
    if (!data.acik) {
        return 'Konsolda bir şey görülmedi.';
    }

    if (data.bekliyor) {
        return `Sizden bekleniyor:\n${data.sonMesaj}`;
    }

    return data.ajanVar ? 'Konsol açık; cihazdan çıktı bekleniyor…' : 'Ajan PC\'ye kuruluyor…';
}

function start(root) {
    const output = root.querySelector('[data-console-screen]');
    const steps = document.querySelector('[data-console-steps]');
    const state = { after: 0, first: true, empty: true, root };
    const screen = { tail: document.createTextNode(''), lines: [], length: 0 };

    active = state;

    async function poll() {
        if (active !== state || !document.body.contains(root)) {
            return;
        }

        let data;

        try {
            const response = await fetch(`${root.dataset.stream}?after=${state.after}`, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            data = await response.json();
        } catch {
            setTimeout(poll, POLL_MS * 3);

            return;
        }

        if (active !== state) {
            return;
        }

        const incoming = clean(data.metin);

        /* Boş ekranın yazısı her yoklamada tazelenir ("elektrik verin" sonradan gelir). */
        if (state.empty && incoming === '' && !state.first) {
            output.textContent = emptyScreen(data);
        }

        if (state.first || incoming !== '') {
            const atBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 40;

            /* İlk çizimde ya da yer tutucu dururken ekran boşaltılır, sonra eklenir. */
            if (state.first || state.empty) {
                resetScreen(output, screen);
            }

            appendScreen(output, screen, incoming);
            state.empty = screen.length === 0 && screen.tail.data === '';

            if (state.empty) {
                output.textContent = emptyScreen(data);
            }

            if (atBottom || state.first) {
                output.scrollTop = output.scrollHeight;
            }
        }

        state.first = false;
        state.after = data.son;
        writeStatus(root, data);

        if (steps) {
            writeSteps(steps, data.olaylar);
        }

        if (data.devami) {
            setTimeout(poll, 50);
        } else if (data.acik) {
            setTimeout(poll, POLL_MS);
        } else if (root.dataset.open === '1') {
            /* Kapanış anında gelen son çıktı için bir tur daha. */
            root.dataset.open = '0';
            setTimeout(poll, POLL_MS);
        }
    }

    poll();
}

async function send(root, payload) {
    const response = await fetch(root.dataset.input, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(payload),
    });

    return response.ok;
}

function setupInput(root) {
    const form = root.querySelector('[data-console-input]');

    if (!form || form.dataset.initialized) {
        return;
    }

    form.dataset.initialized = '1';

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const field = form.elements.namedItem('satir');
        const line = field.value;

        if (await send(root, line === '' ? { tus: 'enter' } : { satir: line })) {
            field.value = '';
        }

        field.focus();
    });

    form.querySelectorAll('[data-console-key]').forEach((button) => {
        button.addEventListener('click', () => send(root, { tus: button.dataset.consoleKey }));
    });
}

export function init() {
    const root = document.getElementById('console-watch');

    if (!root) {
        active = null;

        return;
    }

    setupInput(root);

    if (active?.root === root) {
        return;
    }

    start(root);
}
