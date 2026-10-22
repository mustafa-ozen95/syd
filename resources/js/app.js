import * as bulk from './components/bulk.js';
import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as dialog from './components/dialog.js';
import * as scrollingNavigate from './components/scrolling-navigate.js';
import * as keyboard from './components/keyboard.js';
import * as consoleWatch from './components/console-watch.js';
import * as consoleForm from './components/console-form.js';
import * as userMenu from './components/user-menu.js';
import * as navigation from './components/navigation.js';
import * as sessionLock from './components/session-lock.js';
import * as theme from './components/theme.js';
import * as toast from './components/toast.js';

/**
 * Uygulama girişi.
 *
 * Buradaki modüller yalnızca DOM, olay ve arayüz işleriyle ilgilenir.
 * Yetki ve doğrulama sunucuda yapılır; burada tekrar edilmez.
 *
 * İKİ AYRI KURULUM var, çünkü sayfa geçişleri tarayıcıyı yenilemiyor
 * (bkz. navigation.js):
 *
 *  - BİR KEZ: olayları document üzerinde dinleyen bileşenler. İkinci kez
 *    kurulsalardı her tıklama iki kez işlenirdi.
 *  - HER ÇİZİMDE: düğümün kendisine bakan işler (yeni gelen içerikteki
 *    bildirim, satırların odaklanabilirliği, seçim sayacı).
 *
 * navigation EN SONA kurulur: bağlantıyı önce pencere ve onay bileşenleri
 * görmeli, geçiş yalnızca onlar karışmadıysa devralınmalı.
 *
 * Kardeş panellerle aynı yapı; farklı olan yalnızca bu
 * panele ait modüller (konsol kurulumu).
 */
theme.init();

function onEveryRender() {
    toast.init();
    keyboard.prepare();
    bulk.prepare();

    /* Dar ekranda şerit doğru bölmeye iner; geniş ekranda hiçbir şey yapmaz. */
    columns.init();

    /*
     * Konsol kurulumunun canlı ekranı (app/ConsoleModule): her çizimde, çünkü
     * düğüm yenilemesiz geçişte değişiyor.
     */
    consoleWatch.init();
}

document.addEventListener('DOMContentLoaded', () => {
    dialog.init();
    confirmDialog.init();
    bulk.init();
    copy.init();

    /* Telefonda parmakla bölüm değiştirme; dinleyici gövdede, bir kez kurulur. */
    scrollingNavigate.init();

    keyboard.init();
    userMenu.init();
    sessionLock.init();
    navigation.init();

    /* Konsol "Yeni iş" formu; dinleyici belgede, bir kez. */
    consoleForm.init();

    onEveryRender();
});

document.addEventListener('sys:page', onEveryRender);
