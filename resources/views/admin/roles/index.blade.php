<x-layout.app title="Roller · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Rolün yetkileri canlıdır: kaydedildiği an o roldeki herkes için geçerli. Kişiye özel istisnalar Kullanıcılar ekranından verilir. Hangi rolün bu panele girdiğine kimlik merkezi karar verir.">
                <x-slot:actions>
                    <a href="{{ route('admin.roles.create') }}" class="btn btn--primary btn--sm"><x-icon name="plus" />Yeni rol</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$tabs" />

            <x-ui.flash />

            <x-ui.panel flush>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Rol</th>
                                <th>Kısa ad</th>
                                <th class="text-right">Kullanıcı</th>
                                <th>Yetkiler</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roller as $satir)
                                <tr>
                                    <td data-label="Rol">
                                        <span class="badge badge--{{ $satir['rol']->badge }}">{{ $satir['rol']->label }}</span>
                                        @if ($satir['rol']->aciklama)
                                            <div class="text-sm text-muted">{{ $satir['rol']->aciklama }}</div>
                                        @endif
                                    </td>
                                    <td data-label="Kısa ad" class="mono text-muted">{{ $satir['rol']->slug }}</td>
                                    <td data-label="Kullanıcı" class="mono text-right">{{ $satir['rol']->kullanicilar_count }}</td>
                                    <td data-label="Yetkiler" class="text-sm text-muted">{{ $satir['ozet'] }}</td>
                                    <td>
                                        <div class="table__row-actions">
                                            <a href="{{ route('admin.roles.edit', $satir['rol']) }}" class="btn btn--sm"><x-icon name="edit" />Düzenle</a>
                                            @if ($satir['silinebilir'])
                                                <x-ui.delete-form :action="route('admin.roles.destroy', $satir['rol'])"
                                                                  confirm="{{ $satir['rol']->label }} rolü silinecek."
                                                                  title="Rolü sil"
                                                                  verify="{{ $satir['rol']->slug }}"
                                                                  reasonless />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
