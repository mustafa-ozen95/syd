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

/** Özelleştirme alanlarını seçilen yedekteki değerlerle doldurur. */
async function fillCustom(form) {
    const box = form.querySelector('[data-console-custom]');
    const device = form.querySelector('[data-console-device]')?.value ?? '';
    const file = form.querySelector('[data-console-backup-file]')?.value ?? '';

    if (!box || box.classList.contains('is-hidden') || device === '' || file === '') {
        return;
    }

    try {
        const response = await fetch(`${box.dataset.fieldsUrl}?device=${encodeURIComponent(device)}&file=${encodeURIComponent(file)}`, {
            headers: { Accept: 'application/json' },
        });
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

    out.classList.remove('is-hidden');
    out.textContent = 'Hazırlanıyor…';

    try {
        const response = await fetch(box.dataset.previewUrl, {
            method: 'POST',
            body,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
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
        } else if (target.matches('[data-console-device]')) {
            fillBackups(target);
        } else if (target.matches('[data-console-backup-file]')) {
            fillCustom(target.form);
        }
    });
}
