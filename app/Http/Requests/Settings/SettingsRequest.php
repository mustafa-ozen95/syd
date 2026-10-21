<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Settings\SettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ayar formu.
 *
 * Kurallar KATALOGDAN gelir; forma elle eklenmiş bir alan doğrulamadan
 * geçemez ve kaydedilmez. Kuralları burada tekrar yazmak, katalogla
 * ayrışacak ikinci bir doğruluk kaynağı yaratırdı.
 */
class SettingsRequest extends FormRequest
{
    /**
     * Ayarlar YALNIZCA yöneticide.
     *
     * Burada rol yeterli: bu panelde ayrı bir "ayarları düzenle" yetkisi
     * yok ve uydurmak, kullanılmayan bir kavram eklemek olurdu.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $catalog = app(SettingsCatalog::class);
        $rules = [];

        foreach ($catalog->definitionsFor($this->group()) as $definition) {
            /*
             * Boole alanı işaretlenmediğinde tarayıcı HİÇ göndermez; kural
             * 'required' olsaydı kutuyu kapatmak imkânsız olurdu.
             */
            /*
             * Tanım "nullable" diyorsa ZORUNLU YAPILMAZ: boş bırakılabilen
             * bir ayarı (örneğin rol süzgecini kapatmak) zorunlu kılmak onu
             * kapatılamaz hâle getirirdi.
             */
            $zorunlu = $definition->type !== 'bool'
                && ! in_array('nullable', $definition->rules, true);

            $kurallar = $zorunlu
                ? ['required', ...$definition->rules]
                : ['nullable', ...$definition->rules];

            /*
             * SEÇİM ALANI KENDİ LİSTESİYLE SINIRLANIR.
             *
             * Ekrandaki açılır liste sunucuda da geçerlidir: yalnızca "metin"
             * denetlenseydi forma elle gönderilen uydurma bir değer (ya da
             * listeden kaldırılmış eski bir model adı) kabul edilir ve hata
             * ancak o ayar ilk kez kullanıldığında — kullanıcının
             * karşısında — çıkardı. Kural tanımın kendi seçeneklerinden
             * üretilir; liste değişince kendiliğinden güncellenir.
             */
            if ($definition->type === 'select' && $definition->options() !== []) {
                $kurallar[] = Rule::in(array_keys($definition->options()));
            }

            $rules[$definition->field()] = $kurallar;
        }

        return $rules;
    }

    public function group(): string
    {
        $catalog = app(SettingsCatalog::class);
        $group = $this->string('group')->value();

        return in_array($group, $catalog->groupKeys(), true) ? $group : $catalog->groupKeys()[0];
    }

    /**
     * Form alanlarını ayar anahtarlarına geri çevirir.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $values = [];

        foreach (app(SettingsCatalog::class)->definitionsFor($this->group()) as $definition) {
            $values[$definition->key] = $definition->type === 'bool'
                ? $this->boolean($definition->field())
                : $this->input($definition->field());
        }

        return $values;
    }
}
