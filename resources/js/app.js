import * as bulk from './components/bulk.js';
import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as dialog from './components/dialog.js';
import * as kaydirarakGezin from './components/kaydirarak-gezin.js';
import * as keyboard from './components/keyboard.js';
import * as konsolIzle from './components/konsol-izle.js';
import * as konsolForm from './components/konsol-form.js';
import * as kullaniciMenu from './components/kullanici-menu.js';
import * as navigation from './components/navigation.js';
import * as oturumKilidi from './components/oturum-kilidi.js';
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

function herCizimde() {
    toast.init();
    keyboard.prepare();
    bulk.prepare();

    /* Dar ekranda şerit doğru bölmeye iner; geniş ekranda hiçbir şey yapmaz. */
    columns.init();

    /*
     * Konsol kurulumunun canlı ekranı (app/Konsol): her çizimde, çünkü
     * düğüm yenilemesiz geçişte değişiyor.
     */
    konsolIzle.init();
}

document.addEventListener('DOMContentLoaded', () => {
    dialog.init();
    confirmDialog.init();
    bulk.init();
    copy.init();

    /* Telefonda parmakla bölüm değiştirme; dinleyici gövdede, bir kez kurulur. */
    kaydirarakGezin.init();

    keyboard.init();
    kullaniciMenu.init();
    oturumKilidi.init();
    navigation.init();

    /* Konsol "Yeni iş" formu; dinleyici belgede, bir kez. */
    konsolForm.init();

    herCizimde();
});

document.addEventListener('sys:sayfa', herCizimde);
