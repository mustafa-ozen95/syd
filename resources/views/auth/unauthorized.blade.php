<x-layout.app title="Yetkiniz yok">
<div class="page">
  <div class="page__inner">
    <div class="empty">
      <div class="empty__title">Bu sayfaya erişim yetkiniz yok</div>
      <p class="empty__text">Bu bölüme erişmek için gereken izin hesabınıza tanımlı değil. Yöneticinizle iletişime geçin.</p>
      <a href="{{ route('yedek.index') }}" class="btn btn--primary btn--sm">← Ana sayfaya dön</a>
    </div>
  </div>
</div>
</x-layout.app>
