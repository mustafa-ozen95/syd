<x-layout.app :title="($rol?->label ?? 'Yeni rol').' · Roller'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar" :subtitle="$rol ? $rol->label.' rolünü düzenle' : 'Yeni rol'" />

            <x-ui.tabs :items="$tabs" />

            <x-ui.errors />

            <form method="POST" action="{{ $rol ? route('admin.roles.update', $rol) : route('admin.roles.store') }}"
                  data-confirm="{{ $rol ? $rol->label.' rolünün yetkileri değişecek; roldeki herkes için hemen geçerli.' : 'Yeni rol açılacak.' }}"
                  data-confirm-title="Rolü kaydet"
                  data-confirm-reasonless>
                @csrf
                @if ($rol)
                    @method('PUT')
                @endif

                <x-ui.panel title="Rol">
                    <div class="field-row">
                        <x-form.field name="label" label="Ad">
                            <x-form.input name="label" :value="$rol?->label" required maxlength="60" />
                        </x-form.field>

                        {{-- Kısa ad merkezle eşleşmenin anahtarı: sonradan değişmez. --}}
                        <x-form.field name="slug" label="Kısa ad"
                                      :hint="$rol ? 'Değişmez: kimlik merkezi rolü bu adla bildiriyor.' : 'Kimlik merkezindeki rolün kısa adıyla aynı olmalı. Küçük harf, rakam ve tire.'">
                            <x-form.input name="slug" :value="$rol?->slug" mono maxlength="40"
                                          :required="$rol === null" :disabled="$rol !== null" />
                        </x-form.field>
                    </div>

                    <x-form.field name="aciklama" label="Açıklama">
                        <x-form.input name="aciklama" :value="$rol?->aciklama" maxlength="190" />
                    </x-form.field>

                    <div class="field-row">
                        <x-form.field name="badge" label="Renk">
                            <x-form.select name="badge" :options="$rozetler" :selected="$rol?->badge ?? 'gray'" />
                        </x-form.field>
                        <x-form.field name="sira" label="Sıra">
                            <x-form.input name="sira" type="number" :value="$rol?->sira ?? 0" min="0" max="9999" />
                        </x-form.field>
                    </div>
                </x-ui.panel>

                @if ($rol?->yonetici)
                    <div class="alert alert--info">Yönetici rolü bütün kontrolleri geçer; yetki listesi tutmaz.</div>
                @else
                    <x-ui.panel title="Yetkiler" subtitle="İşaretlenen her yetki bu roldeki herkese verilir. Görüntüle olmadan diğerleri çalışmaz." flush>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Kaynak</th>
                                        <th>Yetkiler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($matris as $satir)
                                        <tr>
                                            <td data-label="Kaynak"><strong>{{ $satir['etiket'] }}</strong></td>
                                            <td data-label="Yetkiler">
                                                <div class="row row--wrap">
                                                    @foreach ($satir['hucreler'] as $hucre)
                                                        <label class="checkbox">
                                                            <input type="checkbox" name="yetkiler[{{ $satir['kaynak'] }}][]"
                                                                   value="{{ $hucre['aksiyon'] }}" @checked($hucre['secili'])>
                                                            <span>{{ $hucre['etiket'] }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.panel>
                @endif

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">{{ $rol ? 'Güncelle' : 'Ekle' }}</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
