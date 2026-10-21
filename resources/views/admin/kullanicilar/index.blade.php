<x-layout.app title="Kullanıcılar">
<div class="page">
  <div class="page__inner page__inner--wide">
    <header class="page-head">
      <div class="page-head__text">
        <h1>Kullanıcı Yönetimi</h1>
        <p class="page-head__subtitle">Panele erişecek kullanıcıları, yetkilerini ve şifre sıfırlama isteklerini buradan yönetin.</p>
      </div>
    </header>

    @if ($yetkiTalepleri->isNotEmpty())
    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title"><x-icon name="key" /> Bekleyen yetki talepleri</h2>
        </div>
      </header>
      <div class="panel__body panel__body--flush">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Kullanıcı</th><th>Talep edilen yetkiler</th><th>Talep zamanı</th><th></th></tr>
            </thead>
            <tbody>
              @foreach ($yetkiTalepleri as $t)
              <tr>
                <td data-label="Kullanıcı" class="satir-baslik">{{ $t->user->name }}</td>
                <td data-label="Talep edilen yetkiler">
                  {{ $t->perm_yedek ? 'Yedekleri görüntüleme ve indirme' : 'hiçbiri (izin kaldırma talebi)' }}
                </td>
                <td data-label="Talep zamanı" class="table__cell--mono">{{ $t->created_at->format('d.m.Y H:i') }}</td>
                <td>
                  <div class="table__row-actions">
                    <form method="post" action="{{ route('admin.yetki-talepleri.onayla', $t) }}">
                      @csrf
                      <button type="submit" class="btn btn--sm btn--primary"><x-icon name="check" />Onayla</button>
                    </form>
                    <form method="post" action="{{ route('admin.yetki-talepleri.reddet', $t) }}">
                      @csrf
                      <button type="submit" class="btn btn--sm">Reddet</button>
                    </form>
                  </div>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>
    @endif

    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title">Kullanıcılar</h2>
        </div>
      </header>
      <div class="panel__body panel__body--flush">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Kullanıcı</th>
                <th>E-posta</th>
                <th>Rol</th>
                <th>Durum</th>
                <th>İzinler</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @foreach ($kullanicilar as $k)
              <tr>
                <td data-label="Kullanıcı" class="satir-baslik">{{ $k->name }}</td>
                <td data-label="E-posta" class="mono text-muted">{{ $k->email ?? '—' }}</td>
                {{-- Rozet rengi rolün kendi kaydından gelir; ekrana koda gömülü renk yazılmaz. --}}
                <td data-label="Rol">
                  @if ($k->rol)
                    <span class="badge badge--{{ $k->rol->badge }}">{{ $k->rol->label }}</span>
                  @else
                    <span class="text-muted">—</span>
                  @endif

                  {{--
                    Rol ekrandan verildiyse merkez eşitlemesi ona dokunmuyor;
                    bunu söylemezsek "merkezde başka rol ama burada yönetici"
                    durumu açıklanamaz görünürdü.
                  --}}
                  @if ($k->rol_yerel)
                    <span class="text-sm text-muted" title="Bu rol panelde verildi; merkez eşitlemesi değiştirmiyor.">· yerel</span>
                  @endif
                </td>
                <td data-label="Durum">
                  <span class="status">
                    <span class="dot {{ $k->aktif ? 'dot--green' : 'dot--gray' }}" aria-hidden="true"></span>
                    {{ $k->aktif ? 'Aktif' : 'Pasif' }}
                  </span>
                </td>
                <td data-label="İzinler" class="text-sm text-muted">{{ $yetkiler[$k->id]['ozet'] }}</td>
                <td>
                  <div class="table__row-actions">
                    <a href="#" class="btn btn--sm" data-modal-open="kullanici-duzenle-{{ $k->id }}"><x-icon name="edit" />Düzenle</a>
                    <form
                      method="post"
                      action="{{ route('admin.kullanicilar.destroy', $k) }}"
                      data-confirm="&quot;{{ $k->email }}&quot; hesabı kalıcı olarak silinecek."
                      data-confirm-title="Kullanıcıyı sil"
                      data-confirm-variant="danger"
                      data-confirm-verify="{{ $k->email }}"
                    >
                      @csrf
                      <button type="submit" class="btn btn--sm btn--danger"><x-icon name="trash" />Sil</button>
                    </form>
                  </div>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{--
      Modallar bilerek `.page__inner`in İÇİNDE (son çocuk olarak) duruyor:
      yenilemesiz geçiş yalnız içerik öğesini değiştiren bir düzende de
      modal sayfayla birlikte gelsin, "Kullanıcı ekle" gibi düğmeler boşa
      tıklanmasın diye. Bu panelde `navigation.js` bütün gövdeyi değiştirir;
      yerleşim iki düzende de çalışır. Boşluk muafiyeti için bkz.
      modal-govde-ici.css.
    --}}
    @foreach ($kullanicilar as $k)
<div
  class="modal"
  id="kullanici-duzenle-{{ $k->id }}"
  data-modal
  @unless ($formlar[$k->id]['acik']) hidden @endunless
  @if ($formlar[$k->id]['acik']) data-modal-acik data-modal-kapanis="{{ route('admin.kullanicilar.index') }}" @endif
  role="dialog" aria-modal="true" aria-labelledby="kullanici-duzenle-{{ $k->id }}-baslik"
>
  <div class="modal__box modal__box--wide">
    <header class="modal__header">
      <h2 class="modal__title" id="kullanici-duzenle-{{ $k->id }}-baslik">{{ $k->name }} — düzenle</h2>
      <button type="button" class="modal__close" data-modal-close aria-label="Kapat">×</button>
    </header>

    <form method="post" action="{{ route('admin.kullanicilar.update', $k) }}">
      @csrf
      <input type="hidden" name="_form" value="duzenle-{{ $k->id }}">
      <div class="modal__body">
        @if ($formlar[$k->id]['acik'] && $errors->any())
        <div class="alert alert--danger modal__alert">
          <ul class="alert__list">
            @foreach ($errors->all() as $hataMesaji)
            <li>{{ $hataMesaji }}</li>
            @endforeach
          </ul>
        </div>
        @endif

        <div class="field">
          <label class="field__label" for="ad-{{ $k->id }}">Ad soyad</label>
          <input type="text" class="input" id="ad-{{ $k->id }}" name="ad"
                 value="{{ $formlar[$k->id]['acik'] ? old('ad') : $k->name }}" required
                 aria-invalid="{{ $formlar[$k->id]['acik'] && $errors->has('ad') ? 'true' : 'false' }}">
        </div>

        <div class="field">
          <label class="field__label" for="eposta-{{ $k->id }}">E-posta <span class="text-muted">(giriş anahtarı)</span></label>
          <input type="email" class="input input--mono" id="eposta-{{ $k->id }}" name="eposta" required
                 value="{{ $formlar[$k->id]['acik'] ? old('eposta') : $k->email }}" placeholder="ad.soyad@ornek.test"
                 aria-invalid="{{ $formlar[$k->id]['acik'] && $errors->has('eposta') ? 'true' : 'false' }}">
        </div>

        <div class="field-row">
            <label class="field__label" for="rol-{{ $k->id }}">Rol</label>
            {{--
              Roller VERİDEN gelir (merkezdekiyle aynı slug'lar). Seçenekleri
              koda gömmek, yeni bir rol eklendiğinde iki yeri birden
              düzenlemeyi gerektirirdi.
            --}}
            <select class="select" id="rol-{{ $k->id }}" name="rol">
              @foreach ($roller as $rol)
                <option value="{{ $rol->slug }}" {{ $formlar[$k->id]['rol'] === $rol->slug ? 'selected' : '' }}>{{ $rol->label }}</option>
              @endforeach
            </select>
          </div>
        </div>

        {{--
          ACİL DURUM PAROLASI.

          Hesapların çoğu merkezden geliyor ve parolası YOK; giriş merkezden
          yapılıyor. Buradaki alan, merkez erişilemez olduğu gün panele
          girilebilsin diye var (kardeş panellerdeki kararla aynı). Boş
          bırakılırsa var olan parola değişmez.
        --}}
        <div class="field field--separated">
          <label class="field__label" for="yeni-sifre-{{ $k->id }}">
            Parola <span class="text-muted">(merkez erişilemezse girebilmek için)</span>
          </label>
          <input type="password" class="input" id="yeni-sifre-{{ $k->id }}" name="yeni_sifre"
                 autocomplete="new-password" placeholder="değişmesin diye boş bırakın"
                 aria-invalid="{{ $formlar[$k->id]['acik'] && $errors->has('yeni_sifre') ? 'true' : 'false' }}">
          <span class="field__hint">En az 12 karakter. Boş bırakılırsa parola değişmez.</span>
        </div>

        <label class="checkbox field--separated">
          <input type="checkbox" name="aktif" value="1" {{ ($formlar[$k->id]['acik'] ? old('aktif') : $k->aktif) ? 'checked' : '' }}>
          Hesap aktif
        </label>

        {{--
          KİŞİYE ÖZEL İSTİSNALAR. Rol canlı okunur; burada yalnızca rolün
          vermediğini eklemek (izin) ya da verdiğini kesmek (yasak) seçilir.
          Rol yetkileri Ayarlar → Roller'dadır.
        --}}
        <div class="field field--separated">
          <span class="field__label">Yetki istisnaları <span class="text-muted">(admin rolü zaten her şeye erişir)</span></span>
          <div class="table-wrap">
            <table class="table">
              <tbody>
                @foreach ($yetkiler[$k->id]['satirlar'] as $satir)
                  <tr>
                    <td data-label="Yetki">
                      <label for="istisna-{{ $k->id }}-{{ $loop->index }}">{{ $satir['etiket'] }}</label>
                      <div class="text-sm text-muted">{{ $satir['rol'] }}</div>
                    </td>
                    <td data-label="Bu kişide">
                      <select class="select" id="istisna-{{ $k->id }}-{{ $loop->index }}" name="istisna[{{ $satir['cift'] }}]">
                        <option value="" @selected($satir['secili'] === '')>Rolden</option>
                        <option value="izin" @selected($satir['secili'] === 'izin')>İzin</option>
                        <option value="yasak" @selected($satir['secili'] === 'yasak')>Yasak</option>
                      </select>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <footer class="modal__footer">
        <button type="button" class="btn btn--secondary" data-modal-close>Vazgeç</button>
        <button type="submit" class="btn btn--primary">Güncelle</button>
      </footer>
    </form>
  </div>
</div>
@endforeach

</x-layout.app>
