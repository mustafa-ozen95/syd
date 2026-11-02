/**
 * Konsol "Yeni iş" formu (app/ConsoleModule, is-fields.blade.php).
 *
 * "Yalnız yedeği yükle"de kurulum alanları (adres, görüntü) gizlenir, görüntü zorunlu olmaktan çıkar.
 * Cihaz "Listede yok" ise switch fabrika ayarında kalır ve yedek alanı gizlenir; listeden bir cihaz
 * seçilince o cihazın yedekleri `data-url`ten çekilir, en yenisi seçili gelir. İlk görünüm sunucudan
 * doğru gelir, burada yalnız değişiklik izlenir.
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

    deviceSelect.form.querySelector('[data-console-backup]')?.classList.toggle('is-hidden', device === '');

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

export function init() {
    document.addEventListener('change', (event) => {
        const target = event.target;

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
        }
    });
}
