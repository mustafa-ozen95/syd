/**
 * Konsol "Yeni iş" formu (app/ConsoleModule, is-fields.blade.php).
 *
 * "Yalnız yedeği yükle"de kurulum alanları (adres, görüntü) gizlenir, görüntü zorunlu olmaktan çıkar.
 * Cihaz "Listede yok" ise switch fabrika ayarında kalır ve yedek alanı gizlenir; listeden bir cihaz
 * seçilince o cihazın yedekleri `data-url`ten çekilir, en yenisi seçili gelir. İlk görünüm sunucudan
 * doğru gelir, burada yalnız değişiklik izlenir.
 *
 * "Yapılandırmayı özelleştir": alanlar seçilen yedekteki değerlerle dolar
 * (`data-fields-url`); "Önizle" ajanın yazacağı satırları sunucudan alır
 * (`data-preview-url`). Kurallar sunucuda; burada yalnız DOM.
 *
 * Dinleyici belgede, bir kez kurulur: pencere yenilemesiz geçişte yeniden
 * çizilse de çalışır.
 */

let requestNo = 0;

function makeOption(value, text) {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = text;

    return o;
}

async function fillBackups(deviceSelect) {
    const fileSelect = deviceSelect.form.querySelector('[data-console-backup-file]');
    const device = deviceSelect.value;

    deviceSelect.form.querySelectorAll('[data-console-backup]').forEach((el) => el.classList.toggle('is-hidden', device === ''));
    showCustomWrap(deviceSelect.form);

    if (!fileSelect) {
        return;
    }

    const seq = ++requestNo;

    if (device === '') {
        fileSelect.replaceChildren();

        return;
    }

    fileSelect.replaceChildren(makeOption('', 'Yükleniyor…'));
    fileSelect.disabled = true;

    try {
        const response = await fetch(`${deviceSelect.dataset.url}?device=${encodeURIComponent(device)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const { yedekler: backups } = await response.json();

        if (seq !== requestNo) {
            return;
        }

        /* En yenisi en üstte ve seçili: çoğu zaman istenen odur. */
        fileSelect.replaceChildren(
            ...(backups.length ? backups.map((y) => makeOption(y.dosya, y.etiket)) : [makeOption('', 'Bu cihazın yedeği yok')]),
        );
        fillCustom(deviceSelect.form);
    } catch {
        if (seq === requestNo) {
            fileSelect.replaceChildren(makeOption('', 'Liste alınamadı; cihazı yeniden seçin'));
        }
    } finally {
        if (seq === requestNo) {
            fileSelect.disabled = false;
        }
    }
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Bilgisayardan seçilen yedek dosyası; yoksa null. */
const uploadedBackup = (form) => form.querySelector('[data-console-backup-upload]')?.files?.[0] ?? null;

/**
 * Özelleştirme bölümü: config şablonu seçiliyse herkese; cihaz yedeği ya da
 * yüklenen dosya seçiliyse yalnız yetkisi olana (data-serbest). Karar
 * sunucuda da verilir; bu yalnız görünüm.
 */
function showCustomWrap(form) {
    const wrap = form.querySelector('[data-console-custom-wrap]');
    const select = form.querySelector('[data-console-device]');
    const device = select?.value ?? '';
    const template = select?.selectedOptions[0]?.hasAttribute('data-sablon') ?? false;
    const free = wrap?.dataset.serbest === '1';

    const visible = (template && !uploadedBackup(form)) || (free && (device !== '' || uploadedBackup(form)));

    wrap?.classList.toggle('is-hidden', !visible);

    /* Gizlenen bölüm gönderilmesin: yetkisiz özelleştirme sunucuda reddedilir. */
    if (!visible) {
        const toggle = wrap?.querySelector('[data-console-custom-toggle]');

        if (toggle) {
            toggle.checked = false;
        }
        wrap?.querySelector('[data-console-custom]')?.classList.add('is-hidden');
    }
}

/** Özelleştirme alanlarını seçilen yedekteki değerlerle doldurur. */
async function fillCustom(form) {
    const box = form.querySelector('[data-console-custom]');
    const device = form.querySelector('[data-console-device]')?.value ?? '';
    const file = form.querySelector('[data-console-backup-file]')?.value ?? '';
    const upload = uploadedBackup(form);

    if (!box || box.classList.contains('is-hidden') || (!upload && (device === '' || file === ''))) {
        return;
    }

    try {
        let response;

        if (upload) {
            const body = new FormData();
            body.append('yedek_yukle', upload);
            response = await fetch(box.dataset.fieldsUrl, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
        } else {
            response = await fetch(`${box.dataset.fieldsUrl}?device=${encodeURIComponent(device)}&file=${encodeURIComponent(file)}`, {
                headers: { Accept: 'application/json' },
            });
        }
        const { alanlar: fields } = await response.json();

        if (!fields) {
            return;
        }
        box.querySelectorAll('[data-oz]').forEach((input) => {
            const value = fields[input.dataset.oz];
            input.value = Array.isArray(value) ? value.join('\n') : (value ?? '');
        });
        /* Yedekte olmayan filtrenin listesi gösterilmez. */
        box.querySelectorAll('[data-oz-field]').forEach((field) => {
            field.classList.toggle('is-hidden', fields[field.dataset.ozField] === null);
        });
    } catch {
        /* Alanlar boş kalır: boş alan "dokunma" demektir. */
    }
}

async function preview(button) {
    const box = button.closest('[data-console-custom]');
    const out = box.querySelector('[data-console-preview-out]');
    const body = new FormData(button.form);

    /* Junos dosyası önizlemeye gerekmez; yüzlerce MB gönderilmesin. */
    body.delete('goruntu_yukle');

    out.classList.remove('is-hidden');
    out.textContent = 'Hazırlanıyor…';

    try {
        const response = await fetch(box.dataset.previewUrl, {
            method: 'POST',
            body,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        const data = await response.json();

        if (response.status === 422) {
            out.textContent = Object.values(data.errors ?? {}).flat().join('\n');
        } else {
            out.textContent = data.satirlar.length
                ? `# ${data.ozet}\n${data.satirlar.join('\n')}`
                : 'Değişiklik yok: alanlar yedektekiyle aynı.';
        }
    } catch {
        out.textContent = 'Önizleme alınamadı.';
    }
}

export function init() {
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-console-preview]') : null;

        if (button) {
            preview(button);
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;

        if (target instanceof HTMLInputElement && target.matches('[data-console-backup-upload]') && target.form) {
            showCustomWrap(target.form);
            fillCustom(target.form);

            return;
        }

        if (target instanceof HTMLInputElement && target.matches('[data-console-custom-toggle]') && target.form) {
            target.form.querySelector('[data-console-custom]')?.classList.toggle('is-hidden', !target.checked);
            if (target.checked) {
                fillCustom(target.form);
            }

            return;
        }

        if (!(target instanceof HTMLSelectElement) || !target.form) {
            return;
        }

        if (target.matches('[data-console-mode]')) {
            const onlyBackup = target.value === 'yedek';

            target.form.querySelector('[data-console-install]')?.classList.toggle('is-hidden', onlyBackup);
            /* CLI'den kurulumda loader yok: adres alanları gizlenir. */
            target.form.querySelector('[data-console-loader]')?.classList.toggle('is-hidden', target.value === 'cli');
            const image = target.form.querySelector('[data-console-image]');

            if (image) {
                image.required = !onlyBackup;
            }
        } else if (target.matches('[data-console-address]')) {
            target.form.querySelectorAll('[data-console-fixed]').forEach((field) => {
                field.classList.toggle('is-hidden', target.value === 'dhcp');
            });
        } else if (target.matches('[data-console-pc]')) {
            /* PC değişince adres onun kaydındakiyle dolar. */
            const selected = target.selectedOptions[0];
            const ip = target.form.querySelector('[data-console-ip]');
            const gatewayInput = target.form.querySelector('[data-console-gateway]');

            if (selected && ip && gatewayInput) {
                ip.value = selected.dataset.ip ?? '';
                gatewayInput.value = selected.dataset.gateway ?? '';
            }

            /* Port seçenekleri o PC'nin portları: tek portta "Otomatik" de var, çok portta seçmek şart. */
            const portSelect = target.form.querySelector('[data-console-port]');

            if (selected && portSelect) {
                const ports = JSON.parse(selected.dataset.ports || '[]');

                portSelect.replaceChildren(
                    ...(ports.length > 1 ? [] : [makeOption('', 'Otomatik')]),
                    ...ports.map((p) => makeOption(p, p)),
                );
            }
        } else if (target.matches('[data-console-device]')) {
            fillBackups(target);
        } else if (target.matches('[data-console-backup-file]')) {
            fillCustom(target.form);
        }
    });
}
