/**
 * Konsol "Yeni iş" formu (app/ConsoleModule, is-fields.blade.php).
 *
 * "Seçilen yedek" dışında yedek cihazı ve dosyası gizlenir; ilk görünüm sunucudan doğru gelir, burada
 * yalnız değişiklik izlenir. Yedek cihazı seçilince o cihazın yedekleri
 * `data-url`ten çekilip dosya kutusuna doldurulur.
 *
 * Dinleyici belgede, bir kez kurulur: pencere yenilemesiz geçişte yeniden
 * çizilse de çalışır.
 */

let requestNo = 0;

function refreshVisibility(form) {
    const configuration = form.querySelector('[data-console-configuration]')?.value;

    form.querySelectorAll('[data-console-only]').forEach((field) => {
        field.classList.toggle('is-hidden', field.dataset.consoleOnly !== configuration);
    });
}

function makeOption(value, text) {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = text;

    return o;
}

async function fillBackups(deviceSelect) {
    const fileSelect = deviceSelect.form.querySelector('[data-console-backup-file]');

    if (!fileSelect) {
        return;
    }

    const seq = ++requestNo;
    const device = deviceSelect.value;

    if (device === '') {
        fileSelect.replaceChildren(makeOption('', 'Önce cihazı seçin'));

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

        fileSelect.replaceChildren(
            makeOption('', backups.length ? '— seçin —' : 'Bu cihazın yedeği yok'),
            ...backups.map((y) => makeOption(y.dosya, y.etiket)),
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

        if (target.matches('[data-console-address]')) {
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
        } else if (target.matches('[data-console-configuration]')) {
            refreshVisibility(target.form);

            /* "Seçilen yedek"e geçince cihaz boşsa "Cihaz" alanındaki ad önerilir. */
            const deviceSelect = target.form.querySelector('[data-console-backup-device]');
            const deviceName = target.form.querySelector('[data-console-device]')?.value;

            if (target.value === 'yedek' && deviceSelect && deviceSelect.value === ''
                && [...deviceSelect.options].some((o) => o.value === deviceName)) {
                deviceSelect.value = deviceName;
                fillBackups(deviceSelect);
            }
        } else if (target.matches('[data-console-backup-device]')) {
            fillBackups(target);
        }
    });
}
