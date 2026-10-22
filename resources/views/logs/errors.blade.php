<x-layout.app title="Hata kayıtları">
<div class="page">
  <div class="page__inner page__inner--wide">
    <header class="page-head">
      <div class="page-head__text">
        <h1>Hata kayıtları</h1>
        <p class="page-head__subtitle">Kuyrukta ya da istek içinde patlayan hatalar. Aynı hata yeni satır açmaz, sayacı artar.</p>
      </div>
    </header>

    <x-logs.tabs aktif="hata" />

    <section class="panel">
      <div class="panel__body panel__body--flush">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Son görülme</th>
                <th>Kaç kez</th>
                <th>Tür</th>
                <th>Mesaj</th>
                <th>Nerede</th>
                <th>Kullanıcı</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($hatalar as $h)
              <tr>
                <td data-label="Son görülme" class="table__cell--mono">{{ $h->last_seen_at?->format('d.m.Y H:i') }}</td>
                <td data-label="Kaç kez">{{ $h->occurrences }}</td>
                <td data-label="Tür" class="table__cell--mono">{{ class_basename($h->type) }}</td>
                <td data-label="Mesaj" class="row-title">{{ \Illuminate\Support\Str::limit($h->message, 160) }}</td>
                <td data-label="Nerede" class="table__cell--mono">
                  {{ $h->context_label }}
                  @if ($h->file)
                    <br><span class="text-sm text-muted">{{ \Illuminate\Support\Str::afterLast($h->file, '/') }}:{{ $h->line }}</span>
                  @endif
                </td>
                <td data-label="Kullanıcı">{{ $h->actor_name ?? '—' }}</td>
              </tr>
              @empty
              <tr><td colspan="6"><div class="empty">Hata kaydı yok — iyi haber.</div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{ $hatalar->links() }}
  </div>
</div>
</x-layout.app>
