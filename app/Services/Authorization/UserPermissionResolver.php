<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\KullaniciIzni;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Yetki kararlarının tek merkezi — kardeş panellerdeki yapının bu panele uyarlaması.
 *
 * İKİ KATMAN VAR: rol ve kişi.
 *
 *   ROL   canlı okunur (roller.yetkiler). Rol ne diyorsa o geçerlidir;
 *         kullanıcıya kopyalanmaz — kopya eskir.
 *   KİŞİ  yalnızca İSTİSNA tutar: rolün vermediğini ekler (izin), verdiğini
 *         keser (yasak). Satırı olmayan yerde rol geçerlidir.
 *
 * Karar sırası (ilk eşleşen kazanır):
 *   1. Yönetici                 -> izin
 *   2. Kapalı hesap             -> ret
 *   3. Kişinin satırı           -> izin / yasak
 *   4. Rolün yetkisi            -> izin
 *   5. Eşleşme yok              -> ret (varsayılan kapalı)
 *
 * Kayıt bazı yok: bu panelin kaynakları (yedekler, Junos listesi) tek
 * parça; grup bazında "şu grupta hariç" istisnası burada karşılık bulmuyor.
 */
class UserPermissionResolver
{
    /** @var array<int, array<string, string>> kullanıcı id => ("kaynak:aksiyon" => etki) */
    private array $kisi = [];

    public function __construct(private PermissionRegistry $registry) {}

    public function allows(User $user, string $resource, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isActive() || ! $this->registry->supports($resource, $action)) {
            return false;
        }

        /* Listeyi göremeyen indiremez, ekleyemez (bkz. requires_view). */
        if ($action !== 'view' && config('permissions.requires_view')
            && $this->registry->supports($resource, 'view')
            && ! $this->decide($user, $resource, 'view')) {
            return false;
        }

        return $this->decide($user, $resource, $action);
    }

    /**
     * Kişinin istisnaları: "kaynak:aksiyon" => izin|yasak.
     *
     * @return array<string, string>
     */
    public function istisnalar(User $user): array
    {
        return $this->kisi[$user->getKey()] ??= $user->izinler()
            ->get(['kaynak', 'aksiyon', 'etki'])
            ->mapWithKeys(fn (KullaniciIzni $satir): array => [$satir->kaynak.':'.$satir->aksiyon => $satir->etki])
            ->all();
    }

    /**
     * İstisnaları tek işlemde yeniden yazar. Tanımsız çiftler ve geçersiz
     * etkiler atılır; "rolden" seçilen satır yazılmaz.
     *
     * @param  array<string, string>  $satirlar  "kaynak:aksiyon" => izin|yasak
     */
    public function istisnalariYaz(User $user, array $satirlar): void
    {
        DB::transaction(function () use ($user, $satirlar): void {
            $user->izinler()->delete();

            foreach ($satirlar as $cift => $etki) {
                [$kaynak, $aksiyon] = array_pad(explode(':', (string) $cift, 2), 2, '');

                if ($this->registry->supports($kaynak, $aksiyon)
                    && in_array($etki, [KullaniciIzni::IZIN, KullaniciIzni::YASAK], true)) {
                    $user->izinler()->create(['kaynak' => $kaynak, 'aksiyon' => $aksiyon, 'etki' => $etki]);
                }
            }
        });

        $this->flush($user);
    }

    /**
     * Kişinin bütün kaynaklarda sahip olduğu etkin yetkiler; ekranlardaki
     * "yetkileri" özeti bunu kullanır.
     *
     * @return list<string> "Yedekler · Görüntüle" biçiminde
     */
    public function ozet(User $user): array
    {
        $ozet = [];

        foreach ($this->registry->resourceKeys() as $kaynak) {
            foreach ($this->registry->actionsFor($kaynak) as $aksiyon) {
                if ($this->allows($user, $kaynak, $aksiyon)) {
                    $ozet[] = $this->registry->label($kaynak).' · '.$this->registry->actionLabel($aksiyon);
                }
            }
        }

        return $ozet;
    }

    public function flush(User $user): void
    {
        unset($this->kisi[$user->getKey()]);
        $user->unsetRelation('izinler');
    }

    private function decide(User $user, string $resource, string $action): bool
    {
        $etki = $this->istisnalar($user)[$resource.':'.$action] ?? null;

        if ($etki !== null) {
            return $etki === KullaniciIzni::IZIN;
        }

        return $user->rol?->verir($resource, $action) === true;
    }
}
