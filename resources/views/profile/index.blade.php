<x-layout.app title="Hesabım">
<div class="page">
  <div class="page__inner">
    <header class="page-head">
      <div class="page-head__text">
        <h1>Hesabım</h1>
        <p class="page-head__subtitle">Kendi hesap bilgileriniz ve yetki talepleriniz. Parola bu panele hiç uğramaz: giriş kimlik merkezinden yapılır.</p>
      </div>
    </header>

    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title">Bilgiler</h2>
        </div>
      </header>
      <div class="panel__body">
        @if ($buFormAd && $errors->any())
        <div class="alert alert--danger field--separated">
          <ul class="alert__list">
            @foreach ($errors->all() as $hataMesaji)
            <li>{{ $hataMesaji }}</li>
            @endforeach
          </ul>
        </div>
        @endif

        <form method="post" action="{{ route('profile.name') }}" class="form-narrow">
          @csrf
          <input type="hidden" name="_form" value="ad">
          <div class="field">
            <label class="field__label" for="ad">Ad soyad</label>
            <input
              type="text"
              class="input"
              id="ad"
              name="ad"
              value="{{ $buFormAd ? old('ad') : $navUser->name }}"
              aria-invalid="{{ $buFormAd && $errors->has('ad') ? 'true' : 'false' }}"
              required
            >
          </div>
          <div class="field">
            <span class="field__label">Rol</span>
            <div>
              @if ($navUser->rol)
                <span class="badge badge--{{ $navUser->rol->badge }}">{{ $navUser->rol->label }}</span>
              @else
                <span class="text-muted">rol atanmamış</span>
              @endif
            </div>
            <span class="field__hint">Rolünüzü yalnızca yöneticiniz değiştirebilir.</span>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn--primary btn--sm">Ad soyadnı güncelle</button>
          </div>
        </form>
      </div>
    </section>

    <section class="panel">
      <header class="panel__head">
        <div>
          <h2 class="panel__title">Yetkiler</h2>
          <p class="text-sm text-muted">Sayfa izinlerinizi kendiniz değiştiremezsiniz; istediklerinizi işaretleyip yöneticinizin onayına gönderebilirsiniz.</p>
        </div>
      </header>
      <div class="panel__body">
        <div class="facts field--separated">
          <div class="facts__row">
            <span class="facts__key">Şu anki yetkileriniz</span>
            <span class="facts__value">
              @if ($navUser->isAdmin())
                <span class="badge badge--purple">admin — hepsi</span>
              @else
                {{ $yetkiOzeti }}
              @endif
            </span>
          </div>
        </div>

        @if ($navUser->isAdmin())
        <p class="text-sm text-muted field--separated">Admin rolü zaten tüm sayfalara erişir, yetki talebi göndermeniz gerekmez.</p>
        @elseif ($bekleyenTalep)
        <div class="alert alert--info field--separated">
          <strong>Onay bekleyen talebiniz var:</strong>
          {{ $bekleyenTalep->perm_yedek ? 'Yedekleri görüntüleme ve indirme' : 'hiçbiri (izin kaldırma talebi)' }}
          — {{ $bekleyenTalep->created_at->format('d.m.Y H:i') }}
        </div>
        @else
        <form method="post" action="{{ route('profile.permission-request') }}" class="field--separated">
          @csrf
          <div class="choices">
            <label class="checkbox">
              <input type="checkbox" name="perm_yedek" value="1" @checked($yedekGorur)>
              Yedekleri görüntüleyip indirebilirim
            </label>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn--primary btn--sm">Onaya gönder</button>
          </div>
        </form>
        @endif
      </div>
    </section>
  </div>
</div>
</x-layout.app>
